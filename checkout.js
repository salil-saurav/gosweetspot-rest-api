jQuery(function ($) {
    'use strict';

    /**
     * Configuration & State
     */
    const GSS_CONFIG = {
        ajaxUrl: window.gss_ajax?.ajax_url || '/wp-admin/admin-ajax.php',
        nonce: window.gss_ajax?.nonce || '',
        instanceId: window.gss_settings?.instance_id || 0
    };

    const DOM = {
        calcBtn: '#gss-calculate-shipping',
        optionsContainer: '#gss-shipping-options',
        shipToDifferent: '#ship-to-different-address-checkbox',
        inputs: {
            courier: 'gss_selected_courier',
            cost: 'gss_selected_cost',
            name: 'gss_selected_name',
            quote: 'gss_quote_id',          // New: For API Label Gen
            service: 'gss_carrier_service', // New: For API Label Gen
            carrier: 'gss_carrier_name'     // New: For API Label Gen
        }
    };

    /**
     * Helper: Get value safely
     */
    const getVal = (sel) => {
        const el = document.querySelector(sel);
        return el ? el.value.trim() : '';
    };

    /**
     * Helper: Create or Update Hidden Form Field
     * Ensures data is available in $_POST when "Place Order" is clicked
     */
    const updateHiddenInput = (name, value) => {
        let input = document.getElementById(name.replace(/_/g, '-')); // Try ID first (gss-selected-courier)

        // If not found by ID, look by name or create it inside the checkout form
        if (!input) {
            input = document.querySelector(`input[name="${name}"]`);
        }

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.id = name.replace(/_/g, '-');
            const form = document.querySelector('form.checkout');
            if (form) form.appendChild(input);
        }

        if (input) input.value = value;
    };

    /**
     * 1. Build Address Payload
     */
    const getAddressData = () => {
        const isShipping = $(DOM.shipToDifferent).is(':checked');
        const prefix = isShipping ? 'shipping_' : 'billing_';

        const getField = (f) => getVal(`#${prefix}${f}`);

        return {
            name: `${getField('first_name')} ${getField('last_name')}`,
            address: getField('address_1'),
            suburb: getField('address_2'), // Often used as suburb in NZ
            city: getField('city'),
            postcode: getField('postcode'),
            country: getField('country') || 'NZ'
        };
    };

    /**
     * 2. Calculate Shipping (AJAX)
     */
    const calculateShipping = async (btn) => {
        const $btn = $(btn);
        const originalText = $btn.text();
        const $container = $(DOM.optionsContainer);

        // UI State: Loading
        $btn.text('Calculating...').prop('disabled', true);
        $container.html('<div class="gss-loading">Fetching live rates...</div>');

        const address = getAddressData();

        // Basic Validation
        if (!address.address || !address.city || !address.postcode) {
            alert('Please fill in Address, City, and Postcode to calculate shipping.');
            $btn.text(originalText).prop('disabled', false);
            $container.empty();
            return;
        }

        try {
            const formData = new URLSearchParams({
                action: 'gss_calculate_shipping',
                nonce: GSS_CONFIG.nonce,
                ...address
            });

            const response = await fetch(GSS_CONFIG.ajaxUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success && result.data && result.data.rates) {
                renderRates(result.data.rates);
            } else {
                const msg = result.data?.message || 'No rates found for this address.';
                $container.html(`<div class="woocommerce-error">${msg}</div>`);
            }

        } catch (error) {
            console.error('GSS Error:', error);
            $container.html('<div class="woocommerce-error">Connection error. Please try again.</div>');
        } finally {
            $btn.text(originalText).prop('disabled', false);
        }
    };

    /**
     * 3. Render Rates UI
     */
    const renderRates = (rates) => {
        const $container = $(DOM.optionsContainer);
        $container.empty();

        // Normalize Data
        const validRates = rates
            .map(r => ({
                id: r.CarrierId,
                name: r.CarrierName,
                service: r.CarrierServiceType || r.Service,
                quoteId: r.QuoteId,
                cost: parseFloat(r.Cost),
                time: r.DeliveryTime || 'Standard'
            }))
            .filter(r => r.cost > 0)
            .sort((a, b) => a.cost - b.cost);

        if (validRates.length === 0) {
            $container.html('<div class="woocommerce-info">No valid shipping options available.</div>');
            return;
        }

        // Build HTML
        const $list = $('<ul class="gss-rate-list"></ul>');

        validRates.forEach((rate, index) => {
            const isChecked = index === 0 ? 'checked' : '';
            const costFormatted = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(rate.cost);

            const html = `
                <li>
                    <label class="gss-rate-option">
                        <input type="radio" name="gss_rate_selection" value="${rate.id}" ${isChecked}
                            data-json='${JSON.stringify(rate)}' />
                        <span class="gss-rate-details">
                            <span class="gss-carrier">${rate.name}</span>
                            <span class="gss-time">${rate.time}</span>
                        </span>
                        <span class="gss-price">${costFormatted}</span>
                    </label>
                </li>
            `;
            $list.append(html);
        });

        $container.append('<h4>Select Shipping Method:</h4>').append($list);

        const $confirmBtn = $('<button type="button" class="button alt gss-confirm-btn">Confirm Shipping</button>');
        $container.append($confirmBtn);

        // Auto-select first option data logically (but don't save to session yet)
        updateHiddenFieldsFromData(validRates[0]);

        // Event: Confirm Click
        $confirmBtn.on('click', function () {
            const $selected = $('input[name="gss_rate_selection"]:checked');
            if ($selected.length) {
                confirmSelection($selected, $(this));
            }
        });
    };

    /**
     * 4. Confirm Selection & Update WooCommerce
     */
    const confirmSelection = async ($radio, $btn) => {
        const rateData = $radio.data('json');

        $btn.text('Updating Total...').prop('disabled', true);

        // 1. Populate Hidden Fields for POST
        updateHiddenFieldsFromData(rateData);

        // 2. Save to Session (Server-side)
        try {
            await fetch(GSS_CONFIG.ajaxUrl, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'gss_save_shipping_session',
                    nonce: GSS_CONFIG.nonce,
                    courier: rateData.id,
                    cost: rateData.cost,
                    name: rateData.name
                })
            });

            // 3. Force WooCommerce to update totals
            // We set the shipping method radio to our plugin ID to ensure WC picks it up
            $(`input[value^="gosweetspot:${GSS_CONFIG.instanceId}"]`).prop('checked', true);

            // Trigger WC update
            $('body').trigger('update_checkout');

            // UI Feedback
            $(DOM.optionsContainer).html(`
                <div class="woocommerce-message" style="margin-top:10px;">
                    Selected: <strong>${rateData.name}</strong> (${rateData.time})<br>
                    Please proceed to payment below.
                </div>
            `);

        } catch (err) {
            console.error('Session Save Error', err);
            alert('Could not apply shipping rate. Please try again.');
            $btn.text('Confirm Shipping').prop('disabled', false);
        }
    };

    /**
     * Helper: Push data to hidden inputs
     */
    const updateHiddenFieldsFromData = (data) => {
        updateHiddenInput(DOM.inputs.courier, data.id);
        updateHiddenInput(DOM.inputs.cost, data.cost);
        updateHiddenInput(DOM.inputs.name, data.name);

        // Critical for Label Generation in Cron
        updateHiddenInput(DOM.inputs.quote, data.quoteId || '');
        updateHiddenInput(DOM.inputs.service, data.service || '');
        updateHiddenInput(DOM.inputs.carrier, data.name || '');
    };

    /**
     * Event Listeners
     */
    $(document).on('click', DOM.calcBtn, function (e) {
        e.preventDefault();
        calculateShipping(this);
    });

    // Ensure our method is selected if WooCommerce refreshes
    $(document.body).on('updated_checkout', function () {
        // If we have values set in hidden fields, ensure our method is checked
        if ($(document.getElementById('gss-selected-cost')).val()) {
            $(`input[value^="gosweetspot:${GSS_CONFIG.instanceId}"]`).prop('checked', true);
        }
    });

});
