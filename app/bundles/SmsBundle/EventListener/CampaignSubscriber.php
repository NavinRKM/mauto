<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\SmsBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\NotificationBundle\Helper\NotificationHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Model\SmsModel;
use Mautic\SmsBundle\SmsEvents;

/**
 * Class CampaignSubscriber.
 */
class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * @var SmsModel
     */
    protected $smsModel;

    /*
     * @var NotificationHelper
     */
    protected $notificationhelper;

    /**
     * CampaignSubscriber constructor.
     *
     * @param IntegrationHelper $integrationHelper
     * @param SmsModel          $smsModel
     */
    public function __construct(
        IntegrationHelper $integrationHelper,
        SmsModel $smsModel,
        NotificationHelper $notificationhelper
    ) {
        $this->integrationHelper  = $integrationHelper;
        $this->smsModel           = $smsModel;
        $this->notificationhelper = $notificationhelper;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD     => ['onCampaignBuild', 0],
            SmsEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
        ];
    }

    /**
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $transportChain = $this->factory->get('mautic.sms.transport_chain');
        $transports     = $transportChain->getEnabledTransports();
        $isEnabled      = false;
        foreach ($transports as $transportServiceId=>$transport) {
            $integration = $this->integrationHelper->getIntegrationObject($this->translator->trans($transportServiceId));
            if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
                $isEnabled = true;
                $event->addAction(
                    'sms.send_text_sms',
                    [
                        'label'            => 'mautic.campaign.sms.send_text_sms',
                        'description'      => 'mautic.campaign.sms.send_text_sms.tooltip',
                        'eventName'        => SmsEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                        'formType'         => 'smssend_list',
                        'formTypeOptions'  => ['update_select' => 'campaignevent_properties_sms'],
                        'formTheme'        => 'MauticSmsBundle:FormTheme\SmsSendList',
                        'timelineTemplate' => 'MauticSmsBundle:SubscribedEvents\Timeline:index.html.php',
                        'channel'          => 'sms',
                        'channelIdField'   => 'sms',
                        'order'            => 2,
                    ]
                );
            }
        }
        $this->notificationhelper->sendNotificationonFailure(false, $isEnabled);
    }

    /**
     * @param CampaignExecutionEvent $event
     *
     * @return mixed
     */
    public function onCampaignTriggerAction(CampaignExecutionEvent $event)
    {
        $lead  = $event->getLead();
        $smsId = (int) $event->getConfig()['sms'];
        $sms   = $this->smsModel->getEntity($smsId);

        if (!$sms) {
            return $event->setFailed('mautic.sms.campaign.failed.missing_entity');
        }

        $result = $this->smsModel->sendSms($sms, $lead, ['channel' => ['campaign.event', $event->getEvent()['id']]])[$lead->getId()];
        if ($result['errorResult'] != 'Success') {
            $this->notificationhelper->sendNotificationonFailure(false, false);
        }
        if ('Authenticate' === $result['status']) {
            // Don't fail the event but reschedule it for later
            return $event->setResult(false);
        }

        if (!empty($result['sent'])) {
            $event->setChannel('sms', $sms->getId());
            $event->setResult($result);
        } else {
            $result['failed'] = true;
            $result['reason'] = $result['status'];
            $event->setResult($result);
        }
    }
}
