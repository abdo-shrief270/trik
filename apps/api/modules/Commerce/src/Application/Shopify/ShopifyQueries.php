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
        fulfillments(first: 10) { displayStatus trackingInfo(first: 1) { company number url } }
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

    public const TAGS_ADD = 'mutation ($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { userErrors { field message } } }';

    public const TAGS_REMOVE = 'mutation ($id: ID!, $tags: [String!]!) { tagsRemove(id: $id, tags: $tags) { userErrors { field message } } }';

    /** What orderUpdate would otherwise wipe: it replaces the whole shipping address. */
    public const ORDER_SHIPPING_ADDRESS = 'query ($id: ID!) { order(id: $id) { cancelledAt shippingAddress { firstName lastName phone zip company countryCodeV2 } } }';

    public const ORDER_UPDATE = 'mutation ($input: OrderInput!) { orderUpdate(input: $input) { order { id } userErrors { field message } } }';

    /** Unpaid COD orders: no refund; the items go back to stock; the customer isn't emailed. */
    public const ORDER_CANCEL = <<<'GQL'
        mutation ($orderId: ID!, $staffNote: String) {
          orderCancel(orderId: $orderId, reason: CUSTOMER, refund: false, restock: true, notifyCustomer: false, staffNote: $staffNote) {
            job { id }
            orderCancelUserErrors { field message code }
          }
        }
        GQL;

    public const BULK_STATUS = <<<'GQL'
        query ($id: ID!) {
          node(id: $id) { ... on BulkOperation { id status errorCode objectCount url partialDataUrl } }
        }
        GQL;
}
