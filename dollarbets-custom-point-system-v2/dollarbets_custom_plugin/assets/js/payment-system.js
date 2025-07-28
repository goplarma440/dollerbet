/**
 * DollarBets Payment System JavaScript
 * Fixed version with proper modal handling and error management
 */

let stripe, elements, card;
let currentBetcoins = 0;
let currentPrice = 0;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializePaymentSystem();
});

/**
 * Initialize the payment system
 */
function initializePaymentSystem() {
    // Check if payment configuration is available
    if (typeof dollarbetsPayment === 'undefined' || !dollarbetsPayment.isConfigured) {
        console.warn('DollarBets Payment: Payment system not configured');
        return;
    }
    
    // Initialize Stripe if available
    if (dollarbetsPayment.stripeKey && typeof Stripe !== 'undefined') {
        stripe = Stripe(dollarbetsPayment.stripeKey);
        elements = stripe.elements();
        console.log('DollarBets Payment: Stripe initialized');
    }
    
    // Set up event listeners
    setupEventListeners();
    
    console.log('DollarBets Payment: System initialized');
}

/**
 * Set up event listeners
 */
function setupEventListeners() {
    // Modal close button
    const closeBtn = document.querySelector('.dollarbets-modal-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', closePaymentModal);
    }
    
    // Click outside modal to close
    const modal = document.getElementById('dollarbets-payment-modal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closePaymentModal();
            }
        });
    }
    
    // Payment method selection
    const stripeBtn = document.getElementById('select-stripe');
    if (stripeBtn) {
        stripeBtn.addEventListener('click', selectStripePayment);
    }
    
    const paypalBtn = document.getElementById('select-paypal');
    if (paypalBtn) {
        paypalBtn.addEventListener('click', selectPayPalPayment);
    }
    
    // Back to methods button
    const backBtn = document.getElementById('back-to-methods');
    if (backBtn) {
        backBtn.addEventListener('click', showPaymentMethods);
    }
    
    // Stripe submit button
    const stripeSubmitBtn = document.getElementById('stripe-submit-btn');
    if (stripeSubmitBtn) {
        stripeSubmitBtn.addEventListener('click', submitStripePayment);
    }
}

/**
 * Global function to initiate purchase (called from buttons)
 */
window.initiatePurchase = function(betcoins, price) {
    // Check if payment system is configured
    if (typeof dollarbetsPayment === 'undefined' || !dollarbetsPayment.isConfigured) {
        alert('Payment functionality is not available. Please contact support.');
        return;
    }
    
    currentBetcoins = betcoins;
    currentPrice = price;
    
    // Update modal content
    const betcoinsSpan = document.getElementById('modal-betcoins-amount');
    const priceSpan = document.getElementById('modal-price-amount');
    
    if (betcoinsSpan) betcoinsSpan.textContent = betcoins.toLocaleString();
    if (priceSpan) priceSpan.textContent = price.toFixed(2);
    
    // Show modal
    showPaymentModal();
    
    // Reset to payment method selection
    showPaymentMethods();
};

/**
 * Show payment modal
 */
function showPaymentModal() {
    const modal = document.getElementById('dollarbets-payment-modal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }
}

/**
 * Close payment modal
 */
function closePaymentModal() {
    const modal = document.getElementById('dollarbets-payment-modal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }
    
    // Reset form state
    resetPaymentForm();
}

/**
 * Show payment method selection
 */
function showPaymentMethods() {
    // Hide all payment forms
    hideElement('stripe-payment-form');
    hideElement('paypal-payment-form');
    hideElement('payment-status');
    hideElement('back-to-methods');
    
    // Show payment method selection
    showElement('payment-method-selection');
    
    // Clear any existing PayPal buttons
    const paypalContainer = document.getElementById('paypal-button-container');
    if (paypalContainer) {
        paypalContainer.innerHTML = '';
    }
}

/**
 * Select Stripe payment method
 */
function selectStripePayment() {
    if (!stripe || !elements) {
        showPaymentStatus('Stripe is not available. Please try another payment method.', 'error');
        return;
    }
    
    // Hide payment method selection
    hideElement('payment-method-selection');
    
    // Show Stripe form
    showElement('stripe-payment-form');
    showElement('back-to-methods');
    
    // Initialize card element if not already done
    if (!card) {
        card = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#424770',
                    '::placeholder': {
                        color: '#aab7c4',
                    },
                },
                invalid: {
                    color: '#9e2146',
                },
            },
        });
        
        card.mount('#stripe-card-element');
        
        // Handle real-time validation errors from the card Element
        card.on('change', function(event) {
            const displayError = document.getElementById('stripe-card-errors');
            const submitBtn = document.getElementById('stripe-submit-btn');
            
            if (event.error) {
                displayError.textContent = event.error.message;
                submitBtn.disabled = true;
            } else {
                displayError.textContent = '';
                submitBtn.disabled = !event.complete;
            }
        });
    }
}

/**
 * Select PayPal payment method
 */
function selectPayPalPayment() {
    if (typeof paypal === 'undefined') {
        showPaymentStatus('PayPal is not available. Please try another payment method.', 'error');
        return;
    }
    
    // Hide payment method selection
    hideElement('payment-method-selection');
    
    // Show PayPal form
    showElement('paypal-payment-form');
    showElement('back-to-methods');
    
    // Initialize PayPal buttons
    initializePayPalButtons();
}

/**
 * Submit Stripe payment
 */
async function submitStripePayment() {
    if (!stripe || !card) {
        showPaymentStatus('Stripe is not properly initialized.', 'error');
        return;
    }
    
    const submitBtn = document.getElementById('stripe-submit-btn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing...';
    
    showPaymentStatus('Creating payment...', 'info');
    
    try {
        // Create payment intent
        const response = await fetch(dollarbetsPayment.restUrl + 'dollarbets/v1/create-payment-intent', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': dollarbetsPayment.nonce
            },
            body: JSON.stringify({
                amount: currentPrice,
                betcoins: currentBetcoins
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to create payment intent');
        }
        
        showPaymentStatus('Confirming payment...', 'info');
        
        // Confirm payment with Stripe
        const {error} = await stripe.confirmCardPayment(result.client_secret, {
            payment_method: {
                card: card
            }
        });
        
        if (error) {
            throw new Error(error.message);
        }
        
        showPaymentStatus('Finalizing purchase...', 'info');
        
        // Confirm with backend
        const confirmResponse = await fetch(dollarbetsPayment.restUrl + 'dollarbets/v1/confirm-payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': dollarbetsPayment.nonce
            },
            body: JSON.stringify({
                payment_intent_id: result.payment_intent_id,
                betcoins: currentBetcoins
            })
        });
        
        const confirmResult = await confirmResponse.json();
        
        if (confirmResult.success) {
            showPaymentStatus(
                `Success! ${confirmResult.betcoins_awarded.toLocaleString()} BetCoins have been added to your account.`,
                'success'
            );
            
            // Reload page after delay to show updated balance
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        } else {
            throw new Error(confirmResult.message || 'Payment confirmation failed');
        }
        
    } catch (error) {
        console.error('Stripe payment error:', error);
        showPaymentStatus('Payment failed: ' + error.message, 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Complete Payment';
    }
}

/**
 * Initialize PayPal buttons
 */
function initializePayPalButtons() {
    const container = document.getElementById('paypal-button-container');
    if (!container) return;
    
    // Clear existing buttons
    container.innerHTML = '';
    
    paypal.Buttons({
        createOrder: async function(data, actions) {
            showPaymentStatus('Creating PayPal order...', 'info');
            
            try {
                const response = await fetch(dollarbetsPayment.restUrl + 'dollarbets/v1/paypal-create-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': dollarbetsPayment.nonce
                    },
                    body: JSON.stringify({
                        amount: currentPrice,
                        betcoins: currentBetcoins
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to create PayPal order');
                }
                
                return result.order_id;
                
            } catch (error) {
                console.error('PayPal order creation error:', error);
                showPaymentStatus('Failed to create PayPal order: ' + error.message, 'error');
                throw error;
            }
        },
        
        onApprove: async function(data, actions) {
            showPaymentStatus('Processing PayPal payment...', 'info');
            
            try {
                const response = await fetch(dollarbetsPayment.restUrl + 'dollarbets/v1/paypal-capture-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': dollarbetsPayment.nonce
                    },
                    body: JSON.stringify({
                        order_id: data.orderID
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showPaymentStatus(
                        `Success! ${result.betcoins_awarded.toLocaleString()} BetCoins have been added to your account.`,
                        'success'
                    );
                    
                    // Reload page after delay to show updated balance
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                } else {
                    throw new Error(result.message || 'Payment capture failed');
                }
                
            } catch (error) {
                console.error('PayPal capture error:', error);
                showPaymentStatus('PayPal payment failed: ' + error.message, 'error');
            }
        },
        
        onError: function(err) {
            console.error('PayPal error:', err);
            showPaymentStatus('PayPal error occurred. Please try again.', 'error');
        },
        
        onCancel: function(data) {
            showPaymentStatus('PayPal payment was cancelled.', 'warning');
        }
        
    }).render('#paypal-button-container');
}

/**
 * Show payment status message
 */
function showPaymentStatus(message, type) {
    const statusDiv = document.getElementById('payment-status');
    if (!statusDiv) return;
    
    statusDiv.textContent = message;
    statusDiv.className = 'payment-status status-' + type;
    statusDiv.style.display = 'block';
}

/**
 * Reset payment form
 */
function resetPaymentForm() {
    // Unmount Stripe card element
    if (card) {
        card.unmount();
        card = null;
    }
    
    // Clear PayPal buttons
    const paypalContainer = document.getElementById('paypal-button-container');
    if (paypalContainer) {
        paypalContainer.innerHTML = '';
    }
    
    // Clear status
    hideElement('payment-status');
    
    // Reset button states
    const stripeSubmitBtn = document.getElementById('stripe-submit-btn');
    if (stripeSubmitBtn) {
        stripeSubmitBtn.disabled = true;
        stripeSubmitBtn.textContent = 'Complete Payment';
    }
    
    // Clear errors
    const cardErrors = document.getElementById('stripe-card-errors');
    if (cardErrors) {
        cardErrors.textContent = '';
    }
}

/**
 * Utility function to show element
 */
function showElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.style.display = 'block';
    }
}

/**
 * Utility function to hide element
 */
function hideElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.style.display = 'none';
    }
}

/**
 * Handle escape key to close modal
 */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('dollarbets-payment-modal');
        if (modal && modal.style.display === 'block') {
            closePaymentModal();
        }
    }
});

// Debug logging
console.log('DollarBets Payment System loaded');
if (typeof dollarbetsPayment !== 'undefined') {
    console.log('Payment configuration:', {
        hasStripe: !!dollarbetsPayment.stripeKey,
        hasPayPal: !!dollarbetsPayment.paypalClientId,
        isConfigured: dollarbetsPayment.isConfigured
    });
}

