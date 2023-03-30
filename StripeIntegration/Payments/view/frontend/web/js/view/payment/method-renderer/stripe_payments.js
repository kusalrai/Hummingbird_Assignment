define(
    [
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'StripeIntegration_Payments/js/action/get-client-secret',
        'StripeIntegration_Payments/js/action/post-confirm-payment',
        'StripeIntegration_Payments/js/action/post-update-cart',
        'StripeIntegration_Payments/js/action/post-restore-quote',
        'StripeIntegration_Payments/js/view/checkout/trialing_subscriptions',
        'stripe_payments_express',
        'mage/translate',
        'mage/url',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/storage',
        'mage/url',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/model/payment-service'
    ],
    function (
        ko,
        Component,
        globalMessageList,
        quote,
        customer,
        getClientSecretAction,
        confirmPaymentAction,
        updateCartAction,
        restoreQuoteAction,
        trialingSubscriptions,
        stripeExpress,
        $t,
        url,
        $,
        placeOrderAction,
        additionalValidators,
        redirectOnSuccessAction,
        storage,
        urlBuilder,
        agreementValidator,
        customerData,
        paymentService
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/payment/element',
                stripePaymentsShowApplePaySection: false
            },
            redirectAfterPlaceOrder: false,
            elements: null,
            initParams: null,
            paymentElement: null,

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
                        'isInitializing',
                        'isInitialized',
                        'useQuoteBillingAddress',

                        // Saved payment methods dropdown
                        'dropdownOptions',
                        'selection',
                        'isDropdownOpen'
                    ]);

                var self = this;

                this.initParams = window.checkoutConfig.payment.stripe_payments.initParams;
                this.isPaymentFormVisible(false);
                this.isOrderPlaced(false);
                this.isInitializing(true);
                this.isInitialized(false);
                this.useQuoteBillingAddress(false);
                this.collectCvc = ko.computed(this.shouldCollectCvc.bind(this));
                this.isAmex = ko.computed(this.isAmexSelected.bind(this));
                this.cardCvcElement = null;

                trialingSubscriptions().refresh(quote); // This should be initially retrieved via a UIConfig

                var currentTotals = quote.totals();
                var currentShippingAddress = quote.shippingAddress();
                var currentBillingAddress = quote.billingAddress();

                quote.totals.subscribe(function (totals)
                {
                    if (JSON.stringify(totals.total_segments) == JSON.stringify(currentTotals.total_segments))
                        return;

                    currentTotals = totals;

                    trialingSubscriptions().refresh(quote);
                    self.onQuoteTotalsChanged.bind(self)();
                    self.isOrderPlaced(false);
                }, this);

                quote.paymentMethod.subscribe(function (method)
                {
                    if (method.method == this.getCode() && !this.isInitializing())
                    {
                        // We intentionally re-create the element because its container element may have changed
                        var params = window.checkoutConfig.payment.stripe_payments.initParams;
                        this.initPaymentForm(params);
                    }
                }, this);

                quote.billingAddress.subscribe(function(address)
                {
                    if (address && self.paymentElement && self.paymentElement.update && !self.isPaymentFormComplete())
                    {
                        // Remove the postcode & country fields if a billing address has been specified
                        var params = window.checkoutConfig.payment.stripe_payments.initParams;
                        self.paymentElement.update(self.getPaymentElementUpdateOptions(params));
                    }
                });

                return this;
            },

            initSavedPaymentMethods: function()
            {
                // If it is already initialized, do not re-initialize
                if (this.dropdownOptions())
                    return;

                var methods = this.getStripeParam("savedMethods");
                var options = [];

                for (var i in methods)
                {
                    if (methods.hasOwnProperty(i))
                        options.push(methods[i]);
                }

                if (options.length > 0)
                {
                    this.isPaymentFormVisible(false);
                    this.selection(options[0]);
                }
                else
                {
                    this.isPaymentFormVisible(true);
                    this.selection(false);
                }

                this.dropdownOptions(options);
            },

            shouldCollectCvc: function()
            {
                var selection = this.selection();

                if (!selection)
                    return false;

                if (selection.type != 'card')
                    return false;

                return !!selection.cvc;
            },

            isAmexSelected: function()
            {
                var selection = this.selection();

                if (!selection)
                    return false;

                if (selection.type != 'card')
                    return false;

                return (selection.brand == "amex");
            },

            newPaymentMethod: function()
            {
                this.messageContainer.clear();

                this.selection({
                    type: 'new',
                    value: 'new',
                    icon: false,
                    label: $t('New payment method')
                });
                this.isDropdownOpen(false);
                this.isPaymentFormVisible(true);
                if (!this.isInitialized())
                {
                    this.onContainerRendered();
                    this.isInitialized(true);
                }
            },

            getPaymentMethodId: function()
            {
                var selection = this.selection();

                if (selection && typeof selection.value != "undefined" && selection.value != "new")
                    return selection.value;

                return null;
            },

            toggleDropdown: function()
            {
                this.isDropdownOpen(!this.isDropdownOpen());
            },

            getStripeParam: function(param)
            {
                if (typeof window.checkoutConfig.payment.stripe_payments == "undefined")
                    return null;

                if (typeof window.checkoutConfig.payment.stripe_payments.initParams == "undefined")
                    return null;

                if (typeof window.checkoutConfig.payment.stripe_payments.initParams[param] == "undefined")
                    return null;

                return window.checkoutConfig.payment.stripe_payments.initParams[param];
            },

            onQuoteTotalsChanged: function()
            {
                var self = this;
                var clientSecret = this.getStripeParam("clientSecret");
                if (!clientSecret)
                    return;

                this.resetInitParams();
                this.getInitParams(function(params)
                {
                    var clientSecret2 = self.getStripeParam("clientSecret");

                    if (clientSecret2 && clientSecret != clientSecret2)
                    {
                        self.initPaymentForm.bind(self)(params);
                    }
                    else if (self.elements)
                    {
                        self.elements.fetchUpdates();
                    }
                },
                function(exception)
                {
                    return self.crash(exception.message);
                });
            },

            resetInitParams: function()
            {
                this.initParams = null;
            },

            getInitParams: function(onSuccess, onError)
            {
                try
                {
                    if (this.initParams)
                        return onSuccess(this.initParams);

                    var self = this;

                    getClientSecretAction(function(result, outcome, response)
                    {
                        try
                        {
                            var params = JSON.parse(result);

                            for (var prop in params)
                            {
                                if (params.hasOwnProperty(prop))
                                    window.checkoutConfig.payment.stripe_payments.initParams[prop] = params[prop];
                            }

                            self.initParams = window.checkoutConfig.payment.stripe_payments.initParams;
                            return onSuccess(self.initParams);
                        }
                        catch (e)
                        {
                            return onError(e);
                        }
                    });
                }
                catch (e)
                {
                    return onError(e);
                }
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);
                initStripe(this.initParams, function(err)
                {
                    if (err)
                        return self.crash(err);

                    if (!self.getStripeParam("clientSecret"))
                        self.resetInitParams();

                    self.getInitParams(function(params)
                    {
                        self.initSavedPaymentMethods();
                        self.initPaymentForm(params);
                    },
                    function(exception)
                    {
                        return self.crash(exception.message);
                    });
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            getCardCVCOptions: function()
            {
                return {
                  style: {
                    base: {
                  //     iconColor: '#c4f0ff',
                  //     color: '#fff',
                  //     fontWeight: '500',
                  //     fontFamily: 'Roboto, Open Sans, Segoe UI, sans-serif',
                      fontSize: '16px',
                  //     fontSmoothing: 'antialiased',
                  //     ':-webkit-autofill': {
                  //       color: '#fce883',
                  //     },
                  //     '::placeholder': {
                  //       color: '#87BBFD',
                  //     },
                  //   },
                  //   invalid: {
                  //     iconColor: '#FFC7EE',
                  //     color: '#FFC7EE',
                    },
                  },
                };
            },

            onCvcContainerRendered: function()
            {
                var self = this;

                initStripe(this.initParams, function(err)
                {
                    if (err)
                        return self.crash(err);

                    if (!self.getStripeParam("clientSecret"))
                        self.resetInitParams();

                    self.getInitParams(function(params)
                    {
                        var elements = stripe.stripeJs.elements({
                            locale: params.locale
                        });
                        self.cardCvcElement = elements.create('cardCvc', self.getCardCVCOptions());
                        self.cardCvcElement.mount('#stripe-card-cvc-element');
                        self.cardCvcElement.on('change', self.onCvcChange.bind(self));
                    },
                    function(exception)
                    {
                        console.error(exception);
                    });
                });
            },

            onCvcChange: function(event)
            {
                if (event.error)
                    this.selection().cvcError = event.error.message;
                else
                    this.selection().cvcError = null;
            },

            crash: function(message)
            {
                this.isLoading(false);
                var userError = this.getStripeParam("userError");
                if (userError)
                    this.permanentError(userError);
                else
                    this.permanentError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: " + message);
            },

            softCrash: function(message)
            {
                var userError = this.getStripeParam("userError");
                if (userError)
                    this.showError(userError);
                else
                    this.showError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: " + message);
            },

            isCollapsed: function()
            {
                if (this.isChecked() == this.getCode())
                {
                    return false;
                }
                else
                {
                    return true;
                }
            },

            initPaymentForm: function(params)
            {
                this.isInitializing(false);
                this.isLoading(false);

                if (this.isCollapsed()) // Don't render PE with a height of 0
                    return;

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

                this.paymentElement = elements.create('payment', this.getPaymentElementOptions(params));
                this.paymentElement.mount('#stripe-payment-element');
                this.paymentElement.on('change', this.onChange.bind(this));
            },

            getPaymentElementOptions: function(params)
            {
                var options = {};
                if (typeof params.wallets != "undefined" && params.wallets)
                    options.wallets = params.wallets;

                var billingAddress = quote.billingAddress();

                if (billingAddress)
                {
                    try
                    {
                        this.useQuoteBillingAddress(true);

                        var hasState = (billingAddress.region || billingAddress.regionCode || billingAddress.regionId);

                        options.fields = {
                            billingDetails: {
                                name: 'never',
                                email: 'never',
                                phone: (billingAddress.telephone ? 'never' : 'auto'),
                                address: {
                                    line1: ((billingAddress.street.length > 0) ? 'never' : 'auto'),
                                    line2: ((billingAddress.street.length > 0) ? 'never' : 'auto'),
                                    city: billingAddress.city ? 'never' : 'auto',
                                    state: hasState ? 'never' : 'auto',
                                    country: billingAddress.countryId ? 'never' : 'auto',
                                    postalCode: billingAddress.postcode ? 'never' : 'auto'
                                }
                            }
                        };
                    }
                    catch (e)
                    {
                        this.useQuoteBillingAddress(false);

                        options.fields = {};
                        console.warn('Could not retrieve billing address: '  + e.message);
                    }

                    // Set the default billing address in order to enable the Link payment method
                    var billingDetails = this.getBillingDetails();

                    if (billingDetails)
                    {
                        options.defaultValues = {
                            billingDetails: billingDetails
                        };
                    }
                }
                else
                {
                    this.useQuoteBillingAddress(false);
                }

                return options;
            },

            getPaymentElementUpdateOptions: function(params)
            {
                var options = this.getPaymentElementOptions(params);

                if (options.wallets)
                {
                    delete options.wallets;
                }

                return options;
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

            isBillingAddressSet: function()
            {
                return quote.billingAddress() && quote.billingAddress().canUseForBilling();
            },

            isPlaceOrderEnabled: function()
            {
                if (this.stripePaymentsError())
                    return false;

                if (this.permanentError())
                    return false;

                return this.isBillingAddressSet();
            },

            getAddressField: function(field)
            {
                if (!quote.billingAddress())
                    return null;

                var address = quote.billingAddress();

                if (!address[field] || address[field].length == 0)
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

                if (quote.guestEmail)
                    details.email = quote.guestEmail;
                else if (customerData.email)
                    details.email = customerData.email;

                if (Object.keys(details).length > 0)
                    return details;

                return null;
            },

            config: function()
            {
                return window.checkoutConfig.payment[this.getCode()];
            },

            isActive: function(parents)
            {
                return true;
            },

            placeOrder: function()
            {
                this.messageContainer.clear();

                if (!this.isPaymentFormComplete() && !this.getPaymentMethodId())
                    return this.showError($t('Please complete your payment details.'));

                if (!this.validate())
                    return;

                this.clearErrors();
                this.isPlaceOrderActionAllowed(false);
                this.isLoading(true);
                var placeNewOrder = this.placeNewOrder.bind(this);
                var reConfirmPayment = this.onOrderPlaced.bind(this);
                var self = this;

                if (this.isOrderPlaced()) // The order was already placed once but the payment failed
                {
                    updateCartAction(this.getPaymentMethodId(), function(result, outcome, response)
                    {
                        self.isLoading(false);
                        try
                        {
                            var data = JSON.parse(result);
                            if (data.error)
                            {
                                self.showError(data.error);
                            }
                            else if (data.redirect)
                            {
                                $.mage.redirect(data.redirect);
                            }
                            else if (data.placeNewOrder)
                            {
                                placeNewOrder();
                            }
                            else
                            {
                                reConfirmPayment();
                            }
                        }
                        catch (e)
                        {
                            self.showError($t("The order could not be placed. Please contact us for assistance."));
                            console.error(e.message);
                        }
                    });
                }
                else
                {
                    try
                    {
                        placeNewOrder();
                    }
                    catch (e)
                    {
                        self.showError($t("The order could not be placed. Please contact us for assistance."));
                        console.error(e.message);
                    }
                }

                return false;
            },

            placeNewOrder: function()
            {
                var self = this;

                this.isLoading(false); // Needed for the terms and conditions checkbox
                this.getPlaceOrderDeferredObject()
                    .fail(this.handlePlaceOrderErrors.bind(this))
                    .done(this.onOrderPlaced.bind(this))
                    .always(function(response, status, xhr)
                    {
                        if (status != "success")
                        {
                            self.isLoading(false);
                            self.isPlaceOrderEnabled(true);
                        }
                    });
            },

            getSelectedMethod: function(param)
            {
                var selection = this.selection();
                if (!selection)
                    return null;

                if (typeof selection[param] == "undefined")
                    return null;

                return selection[param];
            },

            onOrderPlaced: function(result, outcome, response)
            {
                if (!this.isOrderPlaced() && isNaN(result))
                    return this.softCrash("The order was placed but the response from the server did not include a numeric order ID.");
                else
                    this.isOrderPlaced(true);

                this.isLoading(true);
                var onConfirm = this.onConfirm.bind(this);
                var onFail = this.onFail.bind(this);

                // Non-card based confirms may redirect the customer externally. We restore the quote just before it in case the
                // customer clicks the back button on the browser before authenticating the payment.
                var self = this;
                restoreQuoteAction(function()
                {
                    // If we are confirming the payment with a saved method, we need a client secret and a payment method ID
                    var selectedMethod = self.getSelectedMethod("type");

                    var clientSecret = self.getStripeParam("clientSecret");
                    if (!clientSecret)
                        return self.softCrash("To confirm the payment, a client secret is necessary, but we don't have one.");

                    var isSetup = false;
                    if (clientSecret.indexOf("seti_") === 0)
                        isSetup = true;

                    var confirmParams = {
                        payment_method: self.getSelectedMethod("value"),
                        return_url: self.getStripeParam("successUrl")
                    };

                    var dropDownSelection = self.selection();
                    if (dropDownSelection && dropDownSelection.type == "card" && dropDownSelection.cvc == 1 && !isSetup)
                    {
                        confirmParams.payment_method_options = {
                            card: {
                                cvc: self.cardCvcElement
                            }
                        };
                    }

                    self.confirm.bind(self)(selectedMethod, confirmParams, clientSecret, isSetup, onConfirm, onFail);
                });
            },

            confirm: function(methodType, confirmParams, clientSecret, isSetup, onConfirm, onFail)
            {
                if (!clientSecret)
                    return this.softCrash("To confirm the payment, a client secret is necessary, but we don't have one.");

                if (methodType && methodType != 'new')
                {
                    if (!confirmParams.payment_method)
                        return this.softCrash("To confirm the payment, a saved payment method must be selected, but we don't have one.");

                    if (isSetup)
                    {
                        if (methodType == "card")
                            stripe.stripeJs.confirmCardSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "sepa_debit")
                            stripe.stripeJs.confirmSepaDebitSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "boleto")
                            stripe.stripeJs.confirmBoletoSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "acss_debit")
                            stripe.stripeJs.confirmAcssDebitSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "us_bank_account")
                            stripe.stripeJs.confirmUsBankAccountSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else
                            this.showError($t("This payment method is not supported."));
                    }
                    else
                    {
                        if (methodType == "card")
                            stripe.stripeJs.confirmCardPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "sepa_debit")
                            stripe.stripeJs.confirmSepaDebitPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "boleto")
                            stripe.stripeJs.confirmBoletoPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "acss_debit")
                            stripe.stripeJs.confirmAcssDebitPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "us_bank_account")
                            stripe.stripeJs.confirmUsBankAccountPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else
                            this.showError($t("This payment method is not supported."));
                    }
                }
                else
                {
                    customerData.invalidate(['cart']);

                    confirmParams = this.getConfirmParams();

                    // Confirm the payment using element
                    if (isSetup)
                    {
                        stripe.stripeJs.confirmSetup(confirmParams).then(onConfirm, onFail);
                    }
                    else
                    {
                        stripe.stripeJs.confirmPayment(confirmParams).then(onConfirm, onFail);
                    }
                }
            },

            getConfirmParams: function()
            {
                var params = {
                    elements: this.elements,
                    confirmParams: {
                        return_url: this.getStripeParam("successUrl")
                    }
                };

                if (this.useQuoteBillingAddress())
                {
                    params.confirmParams.payment_method_data = {
                        billing_details: {
                            address: this.getStripeFormattedAddress(quote.billingAddress()),
                            email: this.getBillingEmail(),
                            name: this.getNameFromAddress(quote.billingAddress()),
                            phone: this.getBillingPhone()
                        }
                    };
                }

                return params;
            },

            getStripeFormattedAddress: function(address)
            {
                var stripeAddress = {};

                stripeAddress.state = address.region ? address.region : null;
                stripeAddress.postal_code = address.postcode ? address.postcode : null;
                stripeAddress.country = address.countryId ? address.countryId : null;
                stripeAddress.city = address.city ? address.city : null;

                if (address.street && address.street.length > 0)
                {
                    stripeAddress.line1 = address.street[0];

                    if (address.street.length > 1)
                    {
                        stripeAddress.line2 = address.street[1];
                    }
                    else
                    {
                        stripeAddress.line2 = null;
                    }
                }
                else
                {
                    stripeAddress.line1 = null;
                    stripeAddress.line2 = null;
                }

                return stripeAddress;
            },

            getBillingEmail: function()
            {
                if (quote.guestEmail)
                {
                    return quote.guestEmail;
                }
                else if (window.checkoutConfig.customerData && window.checkoutConfig.customerData.email)
                {
                    return window.checkoutConfig.customerData.email;
                }

                return null;
            },

            getNameFromAddress: function(address)
            {
                if (!address)
                    return null;

                var parts = [];
                if (address.firstname)
                    parts.push(address.firstname);

                if (address.middlename)
                    parts.push(address.middlename);

                if (address.lastname)
                    parts.push(address.lastname);

                return parts.join(" ");
            },

            getBillingPhone: function()
            {
                var billingAddress = quote.billingAddress();
                if (!billingAddress)
                    return null;

                if (billingAddress.telephone)
                    return billingAddress.telephone;

                return null;
            },

            onConfirm: function(result)
            {
                this.isLoading(false);
                if (result.error)
                {
                    this.showError(result.error.message);
                    this.restoreQuote(result);
                }
                else
                {
                    customerData.invalidate(['cart']);
                    var successUrl = this.getStripeParam("successUrl");
                    $.mage.redirect(successUrl);
                }
            },

            onFail: function(result)
            {
                this.isLoading(false);
                this.showError("Could not confirm the payment. Please try again.");
                this.restoreQuote(result);
                console.error(result);
            },

            restoreQuote: function(result)
            {
                var self = this;

                // Logs the error on the order and re-activates the cart
                confirmPaymentAction(result, function(result, outcome, response)
                {
                    var data = JSON.parse(result);
                    if (typeof data.redirect != "undefined")
                    {
                        $.mage.redirect(data.redirect);
                        return;
                    }
                });
            },

            /**
             * @return {*}
             */
            getPlaceOrderDeferredObject: function()
            {
                return placeOrderAction(this.getData(), this.messageContainer);
            },

            handlePlaceOrderErrors: function (result)
            {
                if (result && result.responseJSON && result.responseJSON.message)
                    this.showError(result.responseJSON.message);
                else
                {
                    this.showError($t("The order could not be placed. Please contact us for assistance."));

                    if (result && result.responseText)
                        console.error(result.responseText);
                    else
                        console.error(result);
                }
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.isPlaceOrderEnabled(true);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            validate: function(elm)
            {
                return this.validateCvc() && agreementValidator.validate() && additionalValidators.validate();
            },

            validateCvc: function()
            {
                if (!this.selection())
                    return true;

                if (this.selection().type != "card")
                    return true;

                if (this.selection().cvc != 1)
                    return true;

                if (typeof this.selection().cvcError == "undefined")
                {
                    this.showError($t("Please enter your card's security code."));
                    return false;
                }
                else if (!this.selection().cvcError)
                {
                    return true;
                }
                else
                {
                    this.showError(this.selection().cvcError);
                    return false;
                }

                return true;
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'client_side_confirmation': true,
                        'payment_method': this.getPaymentMethodId()
                    }
                };

                return data;
            },

            clearErrors: function()
            {
                this.stripePaymentsError(null);
            }

        });
    }
);
