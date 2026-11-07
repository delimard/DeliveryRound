<?php

namespace DeliveryRound\EventListeners;

use DeliveryRound\DeliveryRound;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Delivery\DeliveryPostageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Module\AbstractDeliveryModule;

/**
 * Validates the delivery against the address carried by the postage event, so the
 * module does not depend on an address_id being posted with the current request.
 */
class DeliveryPostageListener implements EventSubscriberInterface
{
    public function onGetPostage(DeliveryPostageEvent $event): void
    {
        $zipCode = DeliveryRound::zipCodeFor($event->getAddress(), $event->getCart());

        // No address known: let the core fall back to DeliveryRound::isValidDelivery().
        if ('' === $zipCode) {
            return;
        }

        $isValid = DeliveryRound::isZipCodeDelivered($zipCode);

        $event->setValidModule($isValid);

        $module = $event->getModule();
        if ($module instanceof AbstractDeliveryModule) {
            $event->setDeliveryMode($module->getDeliveryMode());
        }

        if ($isValid) {
            $event->setPostage(DeliveryRound::getPrice());
        }

        $event->stopPropagation();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::getModuleEvent(TheliaEvents::MODULE_DELIVERY_GET_POSTAGE, 'DeliveryRound') => ['onGetPostage', 128],
        ];
    }
}
