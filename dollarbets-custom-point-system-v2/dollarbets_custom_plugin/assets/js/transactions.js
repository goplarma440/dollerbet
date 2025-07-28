document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on the transactions tab
    if (!document.querySelector('.dollarbets-transactions-wrapper')) {
        return;
    }
    
    const config = window.dollarBetsTransactions || {};
    let currentUserId = config.currentUserId;
    let profileUserId = getCurrentProfileUserId();
    
    // DOM elements
    const typeFilter = document.getElementById('transaction-type-filter');
    const periodFilter = document.getElementById('transaction-period-filter');
    const refreshBtn = document.getElementById('refresh-transactions');
    const payoutBtn = document.getElementById('request-payout-btn');
    const payoutModal = document.getElementById('payout-modal');
    const closeModal = document.querySelector('.close-modal');
    const payoutAmountInput = document.getElementById('payout-amount');
    const submitPayoutBtn = document.getElementById('submit-payout');
    const cancelPayoutBtn = document.getElementById('cancel-payout');
    
    // Initialize
    loadTransactions();
    
    // Event listeners
    if (typeFilter) {
        typeFilter.addEventListener('change', loadTransactions);
    }
    
    if (periodFilter) {
        periodFilter.addEventListener('change', loadTransactions);
    }
    
    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadTransactions);
    }
    
    if (payoutBtn) {
        payoutBtn.addEventListener('click', openPayoutModal);
    }
    
    if (closeModal) {
        closeModal.addEventListener('click', closePayoutModal);
    }
    
    if (cancelPayoutBtn) {
        cancelPayoutBtn.addEventListener('click', closePayoutModal);
    }
    
    if (submitPayoutBtn) {
        submitPayoutBtn.addEventListener('click', submitPayoutRequest);
    }
    
    if (payoutAmountInput) {
        payoutAmountInput.addEventListener('input', updatePayoutCalculation);
    }
    
    // Close modal when clicking outside
    if (payoutModal) {
        payoutModal.addEventListener('click', function(e) {
            if (e.target === payoutModal) {
                closePayoutModal();
            }
        });
    }
    
    /**
     * Get current profile user ID from URL or UM data
     */
    function getCurrentProfileUserId() {
        // Try to get from UM global data
        if (window.um && window.um.profile && window.um.profile.user_id) {
            return window.um.profile.user_id;
        }
        
        // Try to get from URL
        const urlParams = new URLSearchParams(window.location.search);
        const umUser = urlParams.get('um_user');
        if (umUser) {
            return umUser;
        }
        
        // Fallback to current user
        return currentUserId;
    }
    
    /**
     * Load transactions from API
     */
    function loadTransactions() {
        const loadingSpinner = document.querySelector('.loading-spinner');
        const transactionsTable = document.querySelector('.transactions-table');
        
        if (loadingSpinner) {
            loadingSpinner.style.display = 'block';
        }
        
        const type = typeFilter ? typeFilter.value : 'all';
        const period = periodFilter ? periodFilter.value : 'all_time';
        
        const url = `${config.restUrl}user-transactions/${profileUserId}?type=${type}&period=${period}`;
        
        fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            credentials: 'include'
        })
        .then(response => response.json())
        .then(data => {
            if (loadingSpinner) {
                loadingSpinner.style.display = 'none';
            }
            
            if (data.success) {
                updateSummary(data.summary);
                renderTransactions(data.transactions);
            } else {
                console.error('Failed to load transactions:', data);
                if (transactionsTable) {
                    transactionsTable.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">Failed to load transactions</div>';
                }
            }
        })
        .catch(error => {
            console.error('Error loading transactions:', error);
            if (loadingSpinner) {
                loadingSpinner.style.display = 'none';
            }
            if (transactionsTable) {
                transactionsTable.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">Error loading transactions</div>';
            }
        });
    }
    
    /**
     * Update summary cards
     */
    function updateSummary(summary) {
        const totalSpent = document.getElementById('total-spent');
        const totalEarned = document.getElementById('total-earned');
        const netProfit = document.getElementById('net-profit');
        const currentBetcoins = document.getElementById('current-betcoins');
        
        if (totalSpent) {
            totalSpent.textContent = '$' + summary.total_spent.toFixed(2);
        }
        
        if (totalEarned) {
            totalEarned.textContent = '$' + summary.total_earned.toFixed(2);
            totalEarned.className = 'summary-value ' + (summary.total_earned > 0 ? 'positive' : '');
        }
        
        if (netProfit) {
            netProfit.textContent = '$' + summary.net_profit.toFixed(2);
            netProfit.className = 'summary-value ' + (summary.net_profit > 0 ? 'positive' : summary.net_profit < 0 ? 'negative' : '');
        }
        
        if (currentBetcoins) {
            currentBetcoins.textContent = summary.current_betcoins.toLocaleString();
        }
    }
    
    /**
     * Render transactions table
     */
    function renderTransactions(transactions) {
        const transactionsTable = document.querySelector('.transactions-table');
        
        if (!transactionsTable) return;
        
        if (transactions.length === 0) {
            transactionsTable.innerHTML = '<div style="padding: 40px; text-align: center; color: #666;">No transactions found</div>';
            return;
        }
        
        let html = '';
        
        transactions.forEach(transaction => {
            const icon = getTransactionIcon(transaction.type);
            const amountClass = transaction.amount > 0 ? 'positive' : transaction.amount < 0 ? 'negative' : 'neutral';
            const amountText = transaction.amount !== 0 ? 
                (transaction.amount > 0 ? '+$' : '-$') + Math.abs(transaction.amount).toFixed(2) :
                transaction.betcoins + ' BetCoins';
            
            html += `
                <div class="transaction-row">
                    <div class="transaction-icon ${transaction.type}">
                        ${icon}
                    </div>
                    <div class="transaction-details">
                        <div class="transaction-title">${transaction.title}</div>
                        <div class="transaction-meta">
                            ${transaction.description} • ${formatDate(transaction.timestamp)} • ${transaction.status}
                        </div>
                    </div>
                    <div class="transaction-amount ${amountClass}">
                        ${amountText}
                    </div>
                </div>
            `;
        });
        
        transactionsTable.innerHTML = html;
    }
    
    /**
     * Get icon for transaction type
     */
    function getTransactionIcon(type) {
        const icons = {
            'bet': '🎯',
            'win': '🏆',
            'purchase': '💳',
            'payout': '💰'
        };
        return icons[type] || '📊';
    }
    
    /**
     * Format date for display
     */
    function formatDate(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }
    
    /**
     * Open payout modal
     */
    function openPayoutModal() {
        if (payoutModal) {
            payoutModal.style.display = 'flex';
            updatePayoutCalculation();
        }
    }
    
    /**
     * Close payout modal
     */
    function closePayoutModal() {
        if (payoutModal) {
            payoutModal.style.display = 'none';
            resetPayoutForm();
        }
    }
    
    /**
     * Reset payout form
     */
    function resetPayoutForm() {
        if (payoutAmountInput) payoutAmountInput.value = '';
        const payoutMethod = document.getElementById('payout-method');
        if (payoutMethod) payoutMethod.value = 'paypal';
        const payoutDetails = document.getElementById('payout-details');
        if (payoutDetails) payoutDetails.value = '';
        
        const payoutStatus = document.getElementById('payout-status');
        if (payoutStatus) {
            payoutStatus.style.display = 'none';
            payoutStatus.className = '';
        }
        
        updatePayoutCalculation();
    }
    
    /**
     * Update payout calculation
     */
    function updatePayoutCalculation() {
        const amount = parseInt(payoutAmountInput ? payoutAmountInput.value : 0) || 0;
        const cashAmount = amount * 0.01; // 100 BetCoins = $1
        const fee = cashAmount * 0.05; // 5% fee
        const finalAmount = cashAmount - fee;
        
        const payoutBetcoins = document.getElementById('payout-betcoins');
        const payoutCash = document.getElementById('payout-cash');
        const payoutFee = document.getElementById('payout-fee');
        const payoutFinal = document.getElementById('payout-final');
        
        if (payoutBetcoins) payoutBetcoins.textContent = amount.toLocaleString();
        if (payoutCash) payoutCash.textContent = '$' + cashAmount.toFixed(2);
        if (payoutFee) payoutFee.textContent = '$' + fee.toFixed(2);
        if (payoutFinal) payoutFinal.textContent = '$' + finalAmount.toFixed(2);
    }
    
    /**
     * Submit payout request
     */
    function submitPayoutRequest() {
        const amount = parseInt(payoutAmountInput ? payoutAmountInput.value : 0) || 0;
        const method = document.getElementById('payout-method') ? document.getElementById('payout-method').value : '';
        const details = document.getElementById('payout-details') ? document.getElementById('payout-details').value : '';
        const payoutStatus = document.getElementById('payout-status');
        
        // Validation
        if (amount < 100) {
            showPayoutStatus('Minimum payout is 100 BetCoins', 'error');
            return;
        }
        
        if (!method) {
            showPayoutStatus('Please select a payout method', 'error');
            return;
        }
        
        if (!details.trim()) {
            showPayoutStatus('Please provide your account details', 'error');
            return;
        }
        
        // Disable submit button
        if (submitPayoutBtn) {
            submitPayoutBtn.disabled = true;
            submitPayoutBtn.textContent = 'Processing...';
        }
        
        // Submit request
        fetch(config.restUrl + 'request-payout', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            credentials: 'include',
            body: JSON.stringify({
                amount: amount,
                method: method,
                details: details
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showPayoutStatus(data.message, 'success');
                setTimeout(() => {
                    closePayoutModal();
                    loadTransactions(); // Refresh transactions
                }, 3000);
            } else {
                showPayoutStatus(data.message || 'Payout request failed', 'error');
            }
        })
        .catch(error => {
            console.error('Error submitting payout:', error);
            showPayoutStatus('Network error. Please try again.', 'error');
        })
        .finally(() => {
            // Re-enable submit button
            if (submitPayoutBtn) {
                submitPayoutBtn.disabled = false;
                submitPayoutBtn.textContent = 'Submit Payout Request';
            }
        });
    }
    
    /**
     * Show payout status message
     */
    function showPayoutStatus(message, type) {
        const payoutStatus = document.getElementById('payout-status');
        if (payoutStatus) {
            payoutStatus.textContent = message;
            payoutStatus.className = type === 'success' ? 'status-success' : 'status-error';
            payoutStatus.style.display = 'block';
        }
    }
});

