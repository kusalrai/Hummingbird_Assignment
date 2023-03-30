define([
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals',
    'Magento_Catalog/js/price-utils',
    'StripeIntegration_Payments/js/view/checkout/trialing_subscriptions',
    'StripeIntegration_Payments/js/view/checkout/summary/prorations'
], function (
    quote,
    totals,
    priceUtils,
    trialingSubscriptions,
    prorations
) {
    'use strict';

    return function (grandTotal)
    {
        return grandTotal.extend(
        {
            totals: quote.getTotals(),

            getValue: function()
            {
                var price = 0;

                if (this.totals())
                    price = totals.getSegment('grand_total').value + trialingSubscriptions().getPureValue() + prorations().getPureValue();

                return grandTotal().getFormattedPrice(price);
            },

            getBaseValue: function () {
                var price = 0;

                if (this.totals())
                    price = this.totals().base_grand_total + trialingSubscriptions().getBasePureValue() + prorations().getBasePureValue();

                return priceUtils.formatPrice(price, quote.getBasePriceFormat());
            }
        });
    };
});
