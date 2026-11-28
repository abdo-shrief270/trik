<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use DateTimeInterface;

/**
 * The GraphQL documents Trik sends. ORDER_FIELDS is shared by the single-order refresh and the
 * bulk backfill, so both hand ShopifyOrderMapper::fromGraphql the same shape. Plain fields only
 * (bulk operations don't take fragments); one nested connection (line items).
 */
final class ShopifyQueries
{
    public const ORDER_FIELDS = <<<'GQL'
        id legacyResourceId name createdAt updatedAt cancelledAt note phone
        displayFinancialStatus displayFulfillmentStatus currencyCode
        currentTotalPriceSet { shopMoney { amount } }
        totalShippingPriceSet { shopMoney { amount } }
        currentTotalDiscountsSet { shopMoney { amount } }
        customer { firstName lastName }
        shippingAddress { name phone address1 address2 city province provinceCode countryCodeV2 }
        billingAddress { name phone }
        fulfillments(first: 10) { trackingInfo(first: 1) { company number url } }
        GQL;

    public const LINE_ITEM_FIELDS = 'id title variantTitle sku quantity currentQuantity originalUnitPriceSet { shopMoney { amount } }';

    public const SHOP = 'query { shop { name myshopifyDomain } }';

    public static function order(): string
    {
        return 'query ($id: ID!) { order(id: $id) { '.self::ORDER_FIELDS.' lineItems(first: 100) { edges { node { '.self::LINE_ITEM_FIELDS.' } } } } }';
    }

    /** The inner query of the backfill: every order placed since $since, oldest first. */
    public static function bulkOrders(DateTimeInterface $since): string
    {
        $filter = 'created_at:>=\''.$since->format('Y-m-d\TH:i:s\Z').'\'';

        return '{ orders(query: '.json_encode($filter).', sortKey: CREATED_AT) { edges { node { '.self::ORDER_FIELDS
            .' lineItems { edges { node { '.self::LINE_ITEM_FIELDS.' } } } } } } }';
    }

    public const BULK_RUN = <<<'GQL'
        mutation ($query: String!) {
          bulkOperationRunQuery(query: $query) {
            bulkOperation { id status }
            userErrors { field message }
          }
        }
        GQL;

    public const BULK_STATUS = <<<'GQL'
        query ($id: ID!) {
          node(id: $id) { ... on BulkOperation { id status errorCode objectCount url partialDataUrl } }
        }
        GQL;
}
