<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\ConfigBundle\Controller;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ConfigController.
 */
class ConfigController extends FormController
{
    /**
     * Controller action for editing the application configuration.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editAction()
    {
        //admin only allowed
        if (!$this->user->isAdmin() && !$this->user->isCustomAdmin()) {
            return $this->accessDenied();
        }
        $event      = new ConfigBuilderEvent($this->get('mautic.helper.paths'), $this->get('mautic.helper.bundle'), $this->user->isAdmin());
        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch(ConfigEvents::CONFIG_ON_GENERATE, $event);
        $fileFields  = $event->getFileFields();
        $formThemes  = $event->getFormThemes();
        $formConfigs = $this->get('mautic.config.mapper')->bindFormConfigsWithRealValues($event->getForms());
        $doNotChange = $this->coreParametersHelper->getParameter('security.restrictedConfigFields');

        $this->mergeParamsWithLocal($formConfigs, $doNotChange);

        // Create the form
        $action = $this->generateUrl('mautic_config_action', ['objectAction' => 'edit']);
        $form   = $this->get('form.factory')->create(
            'config',
            $formConfigs,
            [
                'action'     => $action,
                'fileFields' => $fileFields,
            ]
        );

        /** @var \Mautic\CoreBundle\Configurator\Configurator $configurator */
        $configurator   = $this->get('mautic.configurator');
        $isWritabale    = $configurator->isFileWritable();
        $paramater      = $configurator->getParameters();
        $mailertransport= $paramater['mailer_transport'];
        $maileruser     = $paramater['mailer_user'];
        $emailpassword  = $paramater['mailer_password'];
        $region         = $paramater['mailer_amazon_region'];

        $session        = $this->get('session');
        $data           = $this->request->request->get('config');

        if (isset($data['emailconfig']['mailer_transport'])) {
            $transport = $data['emailconfig']['mailer_transport'];
            $session->set('mailer_transport', $transport);
        }
        if (isset($data['emailconfig']['mailer_user'])) {
            $user     = $data['emailconfig']['mailer_user'];
            $session->set('mailer_user', $user);
        }
        if (isset($data['emailconfig']['mailer_password'])) {
            $password = $data['emailconfig']['mailer_password'];
            $session->set('mailer_password', $password);
        }
        if (isset($data['emailconfig']['mailer_amazon_region'])) {
            $amazonregion   = $data['emailconfig']['mailer_amazon_region'];
            $session->set('mailer_amazon_region', $amazonregion);
        }

        /** @var EmailModel $emailModel */
        $emailModel     = $this->getModel('email');
        $emailValidator = $this->factory->get('mautic.validator.email');
        if ($mailertransport == 'mautic.transport.amazon' && !empty($maileruser) && !empty($emailpassword)) {
            $emails = $emailValidator->getVerifiedEmailList($maileruser, $emailpassword, $region);
            if (!empty($emails)) {
                $emailModel->upAwsEmailVerificationStatus($emails);
            }
        }

        // Check for a submitted form and process it
        if ($this->request->getMethod() == 'POST') {
            if (!$cancelled = $this->isFormCancelled($form)) {
                $isValid = false;
                if ($isWritabale && $isValid = $this->isFormValid($form)) {
                    // Bind request to the form
                    $post     = $this->request->request;
                    $formData = $form->getData();

                    // Dispatch pre-save event. Bundles may need to modify some field values like passwords before save
                    $configEvent = new ConfigEvent($formData, $post);
                    $dispatcher->dispatch(ConfigEvents::CONFIG_PRE_SAVE, $configEvent);
                    $formValues = $configEvent->getConfig();

                    $errors      = $configEvent->getErrors();
                    $fieldErrors = $configEvent->getFieldErrors();

                    if ($errors || $fieldErrors) {
                        foreach ($errors as $message => $messageVars) {
                            $form->addError(
                                new FormError($this->translator->trans($message, $messageVars, 'validators'))
                            );
                        }

                        foreach ($fieldErrors as $key => $fields) {
                            foreach ($fields as $field => $fieldError) {
                                $form[$key][$field]->addError(
                                    new FormError($this->translator->trans($fieldError[0], $fieldError[1], 'validators'))
                                );
                            }
                        }
                        $isValid = false;
                    } else {
                        // Prevent these from getting overwritten with empty values
                        $unsetIfEmpty = $configEvent->getPreservedFields();
                        $unsetIfEmpty = array_merge($unsetIfEmpty, $fileFields);

                        // Merge each bundle's updated configuration into the local configuration
                        foreach ($formValues as $key => $object) {
                            if ($key == 'smsconfig') {
                                $object = $this->saveSMSConfig($object);
                            }
                            $checkThese = array_intersect(array_keys($object), $unsetIfEmpty);
                            foreach ($checkThese as $checkMe) {
                                if (empty($object[$checkMe])) {
                                    unset($object[$checkMe]);
                                }
                            }

                            $configurator->mergeParameters($object);
                        }

                        try {
                            // Ensure the config has a secret key
                            $params = $configurator->getParameters();
                            if (empty($params['secret_key'])) {
                                $configurator->mergeParameters(['secret_key' => EncryptionHelper::generateKey()]);
                            }
                            $emailProvider=$this->translator->trans($params['mailer_transport_name']);
                            if (empty($params['mailer_user']) && $mailertransport == $params['mailer_transport_name']) {
                                $configurator->mergeParameters(['mailer_user' => $maileruser]);
                            } else {
                                $configurator->mergeParameters(['mailer_user' => $params['mailer_user']]);
                            }
                            $emailTransport='';
                            if ($formData['emailconfig']['mailer_transport_name'] != 'le.transport.vialeadsengage') {
                                $emailTransport = $formData['emailconfig']['mailer_transport_name'];
                                //$emailTransport = $params['mailer_transport_name'];
                                $configurator->mergeParameters(['mailer_transport' => $emailTransport]);
                                $this->container->get('mautic.helper.licenseinfo')->intEmailProvider($this->translator->trans($emailTransport));
                            } else {
                                $emailTransport = $params['mailer_transport_name'];
                                $configurator->mergeParameters(['mailer_transport_name' => $emailTransport]);
                                $this->container->get('mautic.helper.licenseinfo')->intEmailProvider($this->translator->trans($emailTransport));
                            }
                            $configurator->write();

                            $this->addFlash('mautic.config.config.notice.updated');

                            // We must clear the application cache for the updated values to take effect
                            /** @var \Mautic\CoreBundle\Helper\CacheHelper $cacheHelper */
                            $cacheHelper = $this->get('mautic.helper.cache');
                            $cacheHelper->clearContainerFile();
                        } catch (\RuntimeException $exception) {
                            $this->addFlash('mautic.config.config.error.not.updated', ['%exception%' => $exception->getMessage()], 'error');
                        }
                    }
                } elseif (!$isWritabale) {
                    $form->addError(
                        new FormError(
                            $this->translator->trans('mautic.config.notwritable')
                        )
                    );
                }
            }

            // If the form is saved or cancelled, redirect back to the dashboard
            if ($cancelled || $isValid) {
                if (!$cancelled && $this->isFormApplied($form)) {
                    return $this->delegateRedirect($this->generateUrl('mautic_config_action', ['objectAction' => 'edit']));
                } else {
                    $loginsession = $this->get('session');

                    $loginsession->set('isLogin', false);

                    return $this->delegateRedirect($this->generateUrl('mautic_contact_index'));
                }
            }
        }

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';
        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel          = $this->factory->getModel('email');
        $awsEmailRepository  =$emailModel->getAwsVerifiedEmailsRepository();
        $awsemailstatus      =$awsEmailRepository->getEntities();

        return $this->delegateView(
            [
                'viewParameters' => [
                    'tmpl'           => $tmpl,
                    'security'       => $this->get('mautic.security'),
                    'form'           => $this->setFormTheme($form, 'MauticConfigBundle:Config:form.html.php', $formThemes),
                    'formConfigs'    => $formConfigs,
                    'isWritable'     => $isWritabale,
                    'verifiedEmails' => $awsemailstatus,
                ],
                'contentTemplate' => 'MauticConfigBundle:Config:form.html.php',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_config_index',
                    'mauticContent' => 'config',
                    'route'         => $this->generateUrl('mautic_config_action', ['objectAction' => 'edit']),
                ],
            ]
        );
    }

    /**
     * @param $objectId
     *
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function downloadAction($objectId)
    {
        //admin only allowed
        if (!$this->user->isAdmin() && !$this->user->isCustomAdmin()) {
            return $this->accessDenied();
        }

        $event      = new ConfigBuilderEvent($this->get('mautic.helper.paths'), $this->get('mautic.helper.bundle'), $this->user->isAdmin());
        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch(ConfigEvents::CONFIG_ON_GENERATE, $event);

        // Extract and base64 encode file contents
        $fileFields = $event->getFileFields();

        if (!in_array($objectId, $fileFields)) {
            return $this->accessDenied();
        }

        $content  = $this->get('mautic.helper.core_parameters')->getParameter($objectId);
        $filename = $this->request->get('filename', $objectId);

        if ($decoded = base64_decode($content)) {
            $response = new Response($decoded);
            $response->headers->set('Content-Type', 'application/force-download');
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename);
            $response->headers->set('Expires', 0);
            $response->headers->set('Cache-Control', 'must-revalidate');
            $response->headers->set('Pragma', 'public');

            return $response;
        }

        return $this->notFound();
    }

    /**
     * @param $objectId
     *
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function removeAction($objectId)
    {
        //admin only allowed
        if (!$this->user->isAdmin() && !$this->user->isCustomAdmin()) {
            return $this->accessDenied();
        }

        $success    = 0;
        $event      = new ConfigBuilderEvent($this->get('mautic.helper.paths'), $this->get('mautic.helper.bundle'), $this->user->isAdmin());
        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch(ConfigEvents::CONFIG_ON_GENERATE, $event);

        // Extract and base64 encode file contents
        $fileFields = $event->getFileFields();

        if (in_array($objectId, $fileFields)) {
            $configurator = $this->get('mautic.configurator');
            $configurator->mergeParameters([$objectId => null]);
            try {
                $configurator->write();
                // We must clear the application cache for the updated values to take effect
                /** @var \Mautic\CoreBundle\Helper\CacheHelper $cacheHelper */
                $cacheHelper = $this->get('mautic.helper.cache');
                $cacheHelper->clearContainerFile();
                $success = 1;
            } catch (\Exception $exception) {
            }
        }

        return new JsonResponse(['success' => $success]);
    }

    /**
     * Merges default parameters from each subscribed bundle with the local (real) params.
     *
     * @param array $forms
     * @param array $doNotChange
     *
     * @return array
     */
    private function mergeParamsWithLocal(&$forms, $doNotChange)
    {
        // Import the current local configuration, $parameters is defined in this file

        /** @var \AppKernel $kernel */
        $kernel          = $this->container->get('kernel');
        $localConfigFile = $kernel->getLocalConfigFile();

        /** @var $parameters */
        include $localConfigFile;

        $localParams = $parameters;

        foreach ($forms as &$form) {
            // Merge the bundle params with the local params
            foreach ($form['parameters'] as $key => $value) {
                if (in_array($key, $doNotChange)) {
                    unset($form['parameters'][$key]);
                } elseif (array_key_exists($key, $localParams)) {
                    $form['parameters'][$key] = (is_string($localParams[$key])) ? str_replace('%%', '%', $localParams[$key]) : $localParams[$key];
                }
            }
        }
    }

    private function saveSMSConfig($objects)
    {
        $this->unpublishAllSMSSettings();
        /** @var \Mautic\PluginBundle\Helper\IntegrationHelper $integrationHelper */
        $integrationHelper            = $this->factory->getHelper('integration');
        $integrationObject            = $integrationHelper->getIntegrationObject($this->translator->trans($objects['sms_transport']));
        $settings                     = $integrationObject->getIntegrationSettings();
        $apikeys                      = [];
        $features                     = [];
        $features['frequency_number'] = $objects['sms_frequency_number'];
        $features['frequency_time']   = $objects['sms_frequency_time'];

        if ($objects['sms_transport'] == 'mautic.sms.transport.solutioninfini') {
            $apikeys['url']                = $objects['account_url'];
            $apikeys['apikey']             = $objects['account_api_key'];
            $apikeys['senderid']           = $objects['account_sender_id'];
            $objects['account_auth_token'] = '';
            $objects['account_sid']        = '';
            $objects['sms_from_number']    = '';
        } elseif ($objects['sms_transport'] == 'mautic.sms.transport.twilio') {
            $apikeys['username']              = $objects['account_auth_token'];
            $apikeys['password']              = $objects['account_sid'];
            $features['sending_phone_number'] = $objects['sms_from_number'];
            $objects['account_url']           = '';
            $objects['account_api_key']       = '';
            $objects['account_sender_id']     = '';
        }
        $settings->setFeatureSettings($features);
        $settings->setApiKeys($apikeys);
        $settings->setIsPublished($objects['publish_account']);

        // Prevent merged keys
        $integrationObject->encryptAndSetApiKeys($apikeys, $settings);

        $em = $this->get('doctrine.orm.entity_manager');
        $em->persist($settings);
        $em->flush();

        return $objects;
    }

    private function unpublishAllSMSSettings()
    {
        $transportChain = $this->factory->get('mautic.sms.transport_chain');
        $transports     = $transportChain->getEnabledTransports();
        /** @var \Mautic\PluginBundle\Helper\IntegrationHelper $integrationHelper */
        $integrationHelper  = $this->factory->getHelper('integration');
        foreach ($transports as $transportServiceId=>$transport) {
            $integrationObject = $integrationHelper->getIntegrationObject($this->translator->trans($transportServiceId));
            $settings          = $integrationObject->getIntegrationSettings();
            $settings->setIsPublished(false);
            $em = $this->get('doctrine.orm.entity_manager');
            $em->persist($settings);
            $em->flush();
        }
    }
}
