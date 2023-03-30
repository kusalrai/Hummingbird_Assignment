define(
    [
        'ko',
        'uiComponent',
        'Magento_Ui/js/model/messageList',
        'Magento_Customer/js/model/customer',
        'mage/translate',
        'jquery',
        'mage/storage',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/model/messages',
        'uiLayout'
    ],
    function (
        ko,
        Component,
        globalMessageList,
        customer,
        $t,
        $,
        storage,
        customerData,
        messagesModel,
        layout
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/setup_element',
            },
            elements: null,
            initParams: null,

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'paymentElement',
                        'isPaymentFormComplete',
                        'isPaymentFormVisible',
                        'isLoading',
                        'stripePaymentsError',
                        'permanentError',
                        'isOrderPlaced',
                        'isInitialized'
                    ]);

                var self = this;

                this.isPaymentFormVisible(false);
                this.isOrderPlaced(false);
                this.isInitialized(false);

                this.messageContainer = new messagesModel();

                var messagesComponent = {
                    parent: this.name,
                    name: this.name + '.messages',
                    displayArea: 'messages',
                    component: 'Magento_Ui/js/view/messages',
                    config: {
                        messageContainer: this.messageContainer,
                        autoHideTimeOut: -1
                    }
                };

                layout([messagesComponent]);

                return this;
            },

            getStripeParam: function(param)
            {
                if (typeof window.initParams == "undefined")
                    return null;

                if (typeof window.initParams[param] == "undefined")
                    return null;

                return window.initParams[param];
            },

            getInitParams: function(onSuccess, onError)
            {
                try
                {
                    if (window.initParams)
                        return onSuccess(window.initParams);

                    return onError("Could not find initialization params.");
                }
                catch (e)
                {
                    return onError(e.message);
                }
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);
                this.getInitParams(function(initParams)
                {
                    initStripe(initParams, function(err)
                    {
                        if (err)
                            return self.crash(err);

                        if (!self.getStripeParam("clientSecret"))
                            return self.crash("No client secret provided.");

                        self.initPaymentElement(initParams);
                    });
                },
                function(err)
                {
                    self.crash("Could not retrieve initialization params: " + err);
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            crash: function(message)
            {
                this.isLoading(false);
                this.permanentError($t("Sorry, an error has occurred. Please contact us for assistance."));
                console.error("Error: " + message);
            },

            softCrash: function(message)
            {
                this.showError($t("Sorry, an error has occurred. Please contact us for assistance."));
                this.stripePaymentsError(message);
                console.error("Error: " + message);
            },

            initPaymentElement: function(params)
            {
                if (document.getElementById('stripe-payment-element') === null)
                    return this.crash("Cannot initialize Payment Element on a DOM that does not contain a div.stripe-payment-element.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                if (!params.clientSecret)
                    return this.crash("The PaymentElement could not be initialized because no client_secret was provided in the initialization params.");

                if (this.getStripeParam("isOrderPlaced"))
                    this.isOrderPlaced(true);

                var elements = this.elements = stripe.stripeJs.elements({
                    locale: params.locale,
                    clientSecret: params.clientSecret,
                    appearance: this.getStripePaymentElementOptions()
                });

                var options = {};
                if (typeof params.wallets != "undefined" && params.wallets)
                    options.wallets = params.wallets;

                this.paymentElement = elements.create('payment', options);
                this.paymentElement.mount('#stripe-payment-element');
                this.paymentElement.on('change', this.onChange.bind(this));
                this.isLoading(false);
            },

            onChange: function(event)
            {
                this.isLoading(false);
                this.isPaymentFormComplete(event.complete);
            },

            getStripePaymentElementOptions: function()
            {
                return {
                  theme: 'stripe',
                  variables: {
                    colorText: '#32325d',
                    fontFamily: '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif',
                  },
                };
            },

            getAddressField: function(field)
            {
                var address = [];

                if (typeof address[field] == "undefined")
                    return null;

                if (typeof address[field] !== "string" && typeof address[field] !== "object")
                    return null;

                if (address[field].length == 0)
                    return null;

                return address[field];
            },

            getBillingDetails: function()
            {
                var details = {};
                var address = {};

                if (this.getAddressField('city'))
                    address.city = this.getAddressField('city');

                if (this.getAddressField('countryId'))
                    address.country = this.getAddressField('countryId');

                if (this.getAddressField('postcode'))
                    address.postal_code = this.getAddressField('postcode');

                if (this.getAddressField('region'))
                    address.state = this.getAddressField('region');

                if (this.getAddressField('street'))
                {
                    var street = this.getAddressField('street');
                    address.line1 = street[0];

                    if (street.length > 1)
                        address.line2 = street[1];
                }

                if (Object.keys(address).length > 0)
                    details.address = address;

                if (this.getAddressField('telephone'))
                    details.phone = this.getAddressField('telephone');

                if (this.getAddressField('firstname'))
                    details.name = this.getAddressField('firstname') + ' ' + this.getAddressField('lastname');

                if (customerData.email)
                    details.email = customerData.email;

                if (Object.keys(details).length > 0)
                    return details;

                return null;
            },

            config: function()
            {
                return self.initParams;
            },

            onClick: function(result, outcome, response)
            {
                if (!this.isPaymentFormComplete())
                    return this.showError($t('Please complete the payment method details.'));

                this.clearErrors();

                this.isLoading(true);
                var onConfirm = this.onConfirm.bind(this);
                var onFail = this.onFail.bind(this);

                var clientSecret = this.getStripeParam("clientSecret");
                if (!clientSecret)
                    return this.softCrash("To confirm the setup intent, a client secret is necessary, but we don't have one.");

                var confirmParams = {
                    return_url: this.getStripeParam("successUrl")
                };

                this.confirm(clientSecret, onConfirm, onFail);
            },

            confirm: function(clientSecret, onConfirm, onFail)
            {
                if (!clientSecret)
                    return this.softCrash("To confirm the setup intent, a client secret is necessary, but we don't have one.");

                stripe.stripeJs.confirmSetup({
                    elements: this.elements,
                    confirmParams: {
                        return_url: this.getStripeParam("returnUrl")
                    }
                })
                .then(onConfirm, onFail);
            },

            onConfirm: function(result)
            {
                this.isLoading(false);
                if (result.error)
                {
                    this.showError(result.error.message);
                }
                else
                {
                    var successUrl = this.getStripeParam("returnUrl");
                    $.mage.redirect(successUrl);
                }
            },

            onFail: function(result)
            {
                this.isLoading(false);
                this.showError("Could not set up the payment method. Please try again.");
                console.error(result);
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            validate: function(elm)
            {
                return true;
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            clearErrors: function()
            {
                this.messageContainer.clear();
                this.stripePaymentsError(null);
            }

        });
    }
);
