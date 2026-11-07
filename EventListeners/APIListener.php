<?php

namespace DeliveryRound\EventListeners;

use DeliveryRound\DeliveryRound;
use DeliveryRound\Model\DeliveryRoundQuery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Api\Bridge\Propel\Event\DeliveryModuleOptionEvent;
use Thelia\Api\Resource\DeliveryModuleOption;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;

/**
 * Publishes the delivery round as a delivery option, as the other carriers do: the checkout
 * of Thelia 3 (Flexy's delivery step, the API) lists the options of a carrier, and a carrier
 * that publishes none is never offered, even when it serves the address.
 */
class APIListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Session $session,
    ) {
    }

    public function getDeliveryModuleOptions(DeliveryModuleOptionEvent $event): void
    {
        if ($event->getModule()->getId() !== DeliveryRound::getModuleId()) {
            return;
        }

        $locale = $this->session->getLang()->getLocale();
        $zipCode = DeliveryRound::zipCodeFor($event->getAddress(), $event->getCart());
        $isValid = DeliveryRound::isZipCodeDelivered($zipCode);
        $postage = $isValid ? DeliveryRound::getPrice() : 0.0;

        $option = new DeliveryModuleOption();
        $option
            ->setCode(DeliveryRound::getModuleCode())
            ->setValid($isValid)
            ->setTitle((string) $event->getModule()->setLocale($locale)->getTitle())
            ->setDescription($isValid ? $this->describeRounds($zipCode, $locale) : '')
            ->setImage('')
            ->setMinimumDeliveryDate('')
            ->setMaximumDeliveryDate('')
            // The configured price carries no tax of its own, as DeliveryRound::getPostage() returns it.
            ->setPostage($postage)
            ->setPostageTax(0)
            ->setPostageUntaxed($postage)
        ;

        $event->appendDeliveryModuleOptions($option);
    }

    /**
     * The rounds that serve the postal code, one per line: "Mardi : 05100 BRIANCON, Matin".
     * Plain text, escaped where it is printed.
     */
    private function describeRounds(string $zipCode, string $locale): string
    {
        $translator = Translator::getInstance();
        $lines = [];

        foreach (DeliveryRoundQuery::create()->filterByZipCode($zipCode)->orderByDay()->orderByCity()->find() as $round) {
            $line = $translator->trans(ucfirst((string) $round->getDay()), [], DeliveryRound::DOMAIN_NAME, $locale)
                .' : '.$round->getZipCode().' '.$round->getCity();

            foreach ([$round->getAddress(), $round->getDeliveryPeriod()] as $detail) {
                if (null !== $detail && '' !== trim($detail)) {
                    $line .= ', '.trim($detail);
                }
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::MODULE_DELIVERY_GET_OPTIONS => ['getDeliveryModuleOptions', 129],
        ];
    }
}
