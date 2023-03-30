define(
    [
        'ko',
        'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/action/set-payment-information',
        'StripeIntegration_Payments/js/action/post-confirm-payment',
        'StripeIntegration_Payments/js/action/get-client-secret',
        'mage/translate',
        'mage/url',
        'jquery',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/storage',
        'mage/url',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/alert',
        'domReady!'
    ],
    function (
        ko,
        Component,
        globalMessageList,
        quote,
        customer,
        setPaymentInformationAction,
        confirmPaymentAction,
        getClientSecretAction,
        $t,
        url,
        $,
        additionalValidators,
        storage,
        urlBuilder,
        agreementValidator,
        customerData,
        alert
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'StripeIntegration_Payments/multishipping/card_element',
                continueSelector: '#payment-continue',
                cardElement: null,
                token: ko.observable(null)
            },

            initObservable: function ()
            {
                this._super();

                $(this.continueSelector).click(this.onContinue.bind(this));

                return this;
            },

            onCardElementContainerRendered: function()
            {
                var self = this;
                var params = window.checkoutConfig.payment.stripe_payments.initParams;
                this.isLoading(true);
                initStripe(params, function(err)
                {
                    if (err)
                        return self.crash(err);

                    self.initSavedPaymentMethods.bind(self)();
                    self.initPaymentForm.bind(self)(params);
                });
            },

            onContainerRendered: function()
            {
                this.onCardElementContainerRendered();
                this.isInitialized(true);
            },

            initPaymentForm: function(params)
            {
                this.isInitializing(false);
                this.isLoading(false);

                if (this.isCollapsed()) // Don't render PE with a height of 0
                    return;

                if (document.getElementById('stripe-card-element') === null)
                    return this.crash("Cannot initialize Card Element on a DOM that does not contain a div.stripe-card-element.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                var elements = this.elements = stripe.stripeJs.elements({
                    locale: params.locale
                });

                var options = {
                    hidePostalCode: true,
                    style: this.getStripeCardElementStyle()
                };

                this.cardElement = elements.create('card', options);
                this.cardElement.mount('#stripe-card-element');
                this.cardElement.on('change', this.onChange.bind(this));
            },

            getStripeCardElementStyle: function()
            {
                return {
                    base: {
                    //     iconColor: '#c4f0ff',
                    //     color: '#fff',
                    //     fontWeight: '500',
                        fontFamily: '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif',
                        fontSize: '16px',
                    //     fontSmoothing: 'antialiased',
                    //     ':-webkit-autofill': {
                    //         color: '#fce883',
                    //     },
                    //     '::placeholder': {
                    //         color: '#87BBFD',
                    //     },
                    },
                    // invalid: {
                    //     iconColor: '#FFC7EE',
                    //     color: '#FFC7EE',
                    // },
                };
            },

            onSetPaymentMethodFail: function(result)
            {
                this.token(null);
                this.isLoading(false);
                console.error(result);
            },

            onContinue: function(e)
            {
                // If we already have a tokenized payment method, don't do anything
                if (this.token())
                    return;

                var self = this;

                if (!this.isStripeMethodSelected())
                    return;

                e.preventDefault();
                e.stopPropagation();

                if (!this.validatePaymentMethod())
                    return;

                this.isLoading(true);

                if (this.getSelectedMethod("type") && this.getSelectedMethod("type") != "new")
                {
                    self.token(this.getSelectedMethod("value"));
                    setPaymentInformationAction(this.messageContainer, this.getData()).then(function(){
                        $(self.continueSelector).click();
                    }).fail(self.onSetPaymentMethodFail.bind(self));
                }
                else
                {
                    this.createPaymentMethod(function(err)
                    {
                        if (err)
                            return self.showError(err);

                        $(self.continueSelector).click();
                    });
                }
            },

            createPaymentMethod: function(done)
            {
                var self = this;
                this.token(null);

                var options = {
                    type: 'card',
                    card: this.cardElement,
                    billing_details: this.getBillingDetails(),
                };

                stripe.stripeJs.createPaymentMethod(options).then(function(result)
                {
                    if (result.error)
                        return done(result.error.message);

                    self.token(result.paymentMethod.id);

                    setPaymentInformationAction(self.messageContainer, self.getData()).then(function()
                    {
                        done();
                    }).fail(self.onSetPaymentMethodFail.bind(self));
                });
            },

            getData: function()
            {
                var data = {
                    'method': "stripe_payments",
                    'additional_data': {
                        'cc_stripejs_token': this.token()
                    }
                };

                return data;
            },

            showError: function(message)
            {
                this.isLoading(false);
                alert({ content: message });
            },

            validatePaymentMethod: function ()
            {
                var methods = $('[name^="payment["]'), isValid = false;

                if (methods.length === 0)
                    this.showError( $.mage.__('We can\'t complete your order because you don\'t have a payment method set up.') );
                else if (methods.filter('input:radio:checked').length)
                    return true;
                else
                    this.showError( $.mage.__('Please choose a payment method.') );

                return isValid;
            },

            isStripeMethodSelected: function()
            {
                var methods = $('[name^="payment["]');

                if (methods.length === 0)
                    return false;

                var stripe = methods.filter(function(index, value)
                {
                    if (value.id == "p_method_stripe_payments")
                        return value;
                });

                if (stripe.length == 0)
                    return false;

                return stripe[0].checked;
            }
        });
    }
);
