<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace DeliveryRound;

use DeliveryRound\Model\DeliveryRoundQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Core\Install\Database;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\Cart;
use Thelia\Model\Country;
use Thelia\Model\OrderPostage;
use Thelia\Module\AbstractDeliveryModule;
use Thelia\Module\Exception\DeliveryException;

class DeliveryRound extends AbstractDeliveryModule
{
    /** @var string */
    public const DOMAIN_NAME = 'deliveryround';
    public const STATUS_SENT = 'sent';

    /**
     * Creates the table only when it does not exist yet: (re)activating the module
     * must never drop the delivery rounds already configured.
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
        $database = new Database($con);
        $database->insertSql(null, [__DIR__.'/Config/insert.sql']);

        if (null === self::getConfigValue('price')) {
            self::setConfigValue('price', 0);
        }
    }

    /**
     * Fallback used when the delivery address is not known by the caller. During the
     * checkout, the address is provided by the DeliveryPostageEvent and handled by
     * DeliveryRound\EventListeners\DeliveryPostageListener.
     *
     * @throws PropelException
     */
    public function isValidDelivery(Country $country): bool
    {
        $request = $this->getRequest();
        $currentAddressId = $request->request->get('address_id');

        if (empty($currentAddressId)) {
            if (null === $customer = $request->getSession()?->getCustomerUser()) {
                return false;
            }

            $currentAddressId = AddressQuery::create()
                ->filterByCustomer($customer)
                ->filterByIsDefault(1)
                ->select('ID')
                ->findOne();
        }

        $address = null !== $currentAddressId ? AddressQuery::create()->findPk((int) $currentAddressId) : null;

        return null !== $address && self::isZipCodeDelivered((string) $address->getZipcode());
    }

    /**
     * @throws DeliveryException if the cart cannot be delivered by a delivery round
     * @throws PropelException
     */
    public function getPostage(Country $country): float|OrderPostage
    {
        if (!$this->isValidDelivery($country)) {
            throw new DeliveryException(Translator::getInstance()->trans('This module cannot be used on the current cart.', [], self::DOMAIN_NAME));
        }

        return self::getPrice();
    }

    public static function getPrice(): float
    {
        return (float) self::getConfigValue('price', 0);
    }

    public static function isZipCodeDelivered(string $zipCode): bool
    {
        $zipCode = trim($zipCode);

        return '' !== $zipCode && DeliveryRoundQuery::create()->filterByZipCode($zipCode)->exists();
    }

    /**
     * The postal code the carrier is asked about: the address handed by the core, or the
     * cart's own copy of the delivery address when there is none (a guest has no customer
     * address behind the copy).
     */
    public static function zipCodeFor(?Address $address, ?Cart $cart): string
    {
        $zipCode = $address?->getZipcode() ?? $cart?->getCartAddressRelatedByAddressDeliveryId()?->getZipcode();

        return trim((string) $zipCode);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__.'/I18n/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
