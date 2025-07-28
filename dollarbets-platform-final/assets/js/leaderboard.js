/**
 * DollarBets Leaderboard JavaScript
 * Handles real-time updates and interactions
 */

(function($) {
    'use strict';
    
    // Global leaderboard manager
    window.DollarBetsLeaderboard = {
        instances: {},
        
        init: function(containerId, options) {
            const instance = new LeaderboardInstance(containerId, options);
            this.instances[containerId] = instance;
            return instance;
        },
        
        refresh: function(containerId) {
            if (this.instances[containerId]) {
                this.instances[containerId].loadData();
            }
        },
        
        refreshAll: function() {
            Object.values(this.instances).forEach(instance => {
                instance.loadData();
            });
        }
    };
    
    // Individual leaderboard instance
    function LeaderboardInstance(containerId, options) {
        this.container = document.getElementById(containerId);
        this.options = Object.assign({
            type: 'points',
            limit: 10,
            period: 'all_time',
            autoRefresh: true,
            refreshInterval: 30000
        }, options);
        
        this.refreshTimer = null;
        this.isLoading = false;
        
        this.init();
    }
    
    LeaderboardInstance.prototype = {
        init: function() {
            this.bindEvents();
            this.loadData();
            this.setupAutoRefresh();
        },
        
        bindEvents: function() {
            const self = this;
            
            // Type selector
            const typeSelect = this.container.querySelector('.leaderboard-type-select');
            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    self.options.type = this.value;
                    self.loadData();
                });
            }
            
            // Period selector
            const periodSelect = this.container.querySelector('.leaderboard-period-select');
            if (periodSelect) {
                periodSelect.addEventListener('change', function() {
                    self.options.period = this.value;
                    self.loadData();
                });
            }
            
            // Refresh button
            const refreshBtn = this.container.querySelector('.leaderboard-refresh-btn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function() {
                    self.loadData(true);
                });
            }
        },
        
        loadData: function(forceRefresh = false) {
            if (this.isLoading) return;
            
            this.isLoading = true;
            this.showLoading();
            
            const params = new URLSearchParams({
                type: this.options.type,
                period: this.options.period,
                limit: this.options.limit
            });
            
            if (forceRefresh) {
                params.append('_t', Date.now());
            }
            
            fetch(`${dollarBetsLeaderboard.restUrl}leaderboard?${params}`, {
                headers: {
                    'X-WP-Nonce': dollarBetsLeaderboard.nonce
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderData(data.data);
                    this.updateStats(data);
                    this.triggerUpdateEvent(data);
                } else {
                    this.showError('Failed to load leaderboard data');
                }
            })
            .catch(error => {
                console.error('Leaderboard error:', error);
                this.showError('Network error occurred');
            })
            .finally(() => {
                this.isLoading = false;
                this.hideLoading();
            });
        },
        
        renderData: function(data) {
            const tableContainer = this.container.querySelector('.leaderboard-table');
            
            if (!data || data.length === 0) {
                tableContainer.innerHTML = this.getEmptyStateHTML();
                return;
            }
            
            const html = data.map((user, index) => this.renderUserRow(user, index)).join('');
            tableContainer.innerHTML = html;
            
            // Add click handlers for user rows
            this.bindUserRowEvents();
        },
        
        renderUserRow: function(user, index) {
            const rankClass = user.rank <= 3 ? `rank-${user.rank}` : '';
            const profitClass = user.profit_loss >= 0 ? 'positive' : 'negative';
            const profitSign = user.profit_loss >= 0 ? '+' : '';
            
            return `
                <div class="leaderboard-row" data-user-id="${user.user_id}">
                    <div class="rank ${rankClass}">${this.getRankDisplay(user.rank)}</div>
                    <div class="user-info">
                        <img src="${user.avatar}" alt="${user.username}" class="user-avatar" loading="lazy">
                        <div class="user-details">
                            <div class="username">${user.username}</div>
                            <div class="user-badges">
                                ${user.badges.map(badge => `
                                    <span class="badge" style="background-color: ${badge.color}" title="${badge.name}">
                                        ${badge.icon}
                                    </span>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                    <div class="stats">
                        <div class="stat">
                            <span class="stat-value">${this.formatNumber(user.points)}</span>
                            <span class="stat-label">Points</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${user.wins}/${user.total_bets}</span>
                            <span class="stat-label">W/L</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${user.accuracy}%</span>
                            <span class="stat-label">Accuracy</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value ${profitClass}">
                                ${profitSign}${this.formatNumber(user.profit_loss)}
                            </span>
                            <span class="stat-label">P/L</span>
                        </div>
                    </div>
                </div>
            `;
        },
        
        bindUserRowEvents: function() {
            const rows = this.container.querySelectorAll('.leaderboard-row');
            rows.forEach(row => {
                row.addEventListener('click', (e) => {
                    const userId = row.dataset.userId;
                    this.showUserDetails(userId);
                });
            });
        },
        
        showUserDetails: function(userId) {
            // Create modal or expand row to show detailed user stats
            fetch(`${dollarBetsLeaderboard.restUrl}user-stats/${userId}`, {
                headers: {
                    'X-WP-Nonce': dollarBetsLeaderboard.nonce
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.displayUserModal(data.stats);
                }
            })
            .catch(error => {
                console.error('User stats error:', error);
            });
        },
        
        displayUserModal: function(stats) {
            // Simple modal implementation
            const modal = document.createElement('div');
            modal.className = 'dollarbets-user-modal';
            modal.innerHTML = `
                <div class="modal-overlay">
                    <div class="modal-content">
                        <button class="modal-close">&times;</button>
                        <h3>Detailed Statistics</h3>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <span class="stat-value">${this.formatNumber(stats.current_points)}</span>
                                <span class="stat-label">Current Points</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">${stats.total_bets}</span>
                                <span class="stat-label">Total Bets</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">${this.formatNumber(stats.total_wagered)}</span>
                                <span class="stat-label">Total Wagered</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">${stats.win_streak}</span>
                                <span class="stat-label">Best Streak</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">${stats.current_streak}</span>
                                <span class="stat-label">Current Streak</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">${stats.favorite_category}</span>
                                <span class="stat-label">Favorite Category</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Close modal events
            modal.querySelector('.modal-close').addEventListener('click', () => {
                document.body.removeChild(modal);
            });
            
            modal.querySelector('.modal-overlay').addEventListener('click', (e) => {
                if (e.target === modal.querySelector('.modal-overlay')) {
                    document.body.removeChild(modal);
                }
            });
        },
        
        updateStats: function(data) {
            const totalPlayersEl = this.container.querySelector('#total-players');
            const lastUpdatedEl = this.container.querySelector('#last-updated');
            
            if (totalPlayersEl) {
                totalPlayersEl.textContent = data.data.length;
            }
            
            if (lastUpdatedEl) {
                const date = new Date(data.last_updated);
                lastUpdatedEl.textContent = date.toLocaleTimeString();
            }
        },
        
        showLoading: function() {
            const spinner = this.container.querySelector('.loading-spinner');
            if (spinner) {
                spinner.style.display = 'block';
            }
        },
        
        hideLoading: function() {
            const spinner = this.container.querySelector('.loading-spinner');
            if (spinner) {
                spinner.style.display = 'none';
            }
        },
        
        showError: function(message) {
            const tableContainer = this.container.querySelector('.leaderboard-table');
            tableContainer.innerHTML = `
                <div class="error-message">
                    <p>⚠️ ${message}</p>
                    <button onclick="window.DollarBetsLeaderboard.refresh('${this.container.id}')">
                        Try Again
                    </button>
                </div>
            `;
        },
        
        getEmptyStateHTML: function() {
            return `
                <div class="empty-state">
                    <div class="empty-icon">🎯</div>
                    <h4>No Data Available</h4>
                    <p>Place some bets to see the leaderboard!</p>
                </div>
            `;
        },
        
        getRankDisplay: function(rank) {
            if (rank === 1) return '🥇';
            if (rank === 2) return '🥈';
            if (rank === 3) return '🥉';
            return `#${rank}`;
        },
        
        formatNumber: function(num) {
            if (Math.abs(num) >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            }
            if (Math.abs(num) >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toLocaleString();
        },
        
        setupAutoRefresh: function() {
            if (this.options.autoRefresh) {
                this.refreshTimer = setInterval(() => {
                    this.loadData();
                }, this.options.refreshInterval);
            }
        },
        
        destroy: function() {
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
            }
        },
        
        triggerUpdateEvent: function(data) {
            const event = new CustomEvent('dollarBetsLeaderboardUpdate', {
                detail: {
                    containerId: this.container.id,
                    data: data,
                    type: this.options.type,
                    period: this.options.period
                }
            });
            
            document.dispatchEvent(event);
        }
    };
    
    // Auto-initialize leaderboards on page load
    $(document).ready(function() {
        $('.dollarbets-leaderboard').each(function() {
            const container = $(this);
            const options = {
                type: container.data('type') || 'points',
                limit: container.data('limit') || 10,
                period: container.data('period') || 'all_time',
                autoRefresh: container.data('auto-refresh') !== false,
                refreshInterval: (container.data('refresh-interval') || 30) * 1000
            };
            
            window.DollarBetsLeaderboard.init(this.id, options);
        });
    });
    
    // Clean up on page unload
    $(window).on('beforeunload', function() {
        Object.values(window.DollarBetsLeaderboard.instances).forEach(instance => {
            instance.destroy();
        });
    });
    
    // Listen for bet placement events to refresh leaderboard
    $(document).on('dollarBetsBetPlaced', function() {
        window.DollarBetsLeaderboard.refreshAll();
    });
    
    // Listen for prediction resolution events
    $(document).on('dollarBetsPredictionResolved', function() {
        window.DollarBetsLeaderboard.refreshAll();
    });
    
})(jQuery);

// Additional CSS for modal and enhanced features
const additionalCSS = `
.dollarbets-user-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 10000;
}

.dollarbets-user-modal .modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
}

.dollarbets-user-modal .modal-content {
    background: white;
    border-radius: 12px;
    padding: 30px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    position: relative;
}

.dollarbets-user-modal .modal-close {
    position: absolute;
    top: 15px;
    right: 20px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.dollarbets-user-modal .modal-close:hover {
    color: #333;
}

.dollarbets-user-modal .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.dollarbets-user-modal .stat-item {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.dollarbets-user-modal .stat-value {
    display: block;
    font-size: 20px;
    font-weight: bold;
    color: #333;
    margin-bottom: 5px;
}

.dollarbets-user-modal .stat-label {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
}

.error-message {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.error-message button {
    background: #007cba;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
    margin-top: 10px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.empty-state .empty-icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.empty-state h4 {
    margin: 0 0 10px 0;
    color: #333;
}

/* Dark mode for modal */
body.dollarbets-dark-mode .dollarbets-user-modal .modal-content {
    background: #2c2c2c;
    color: #fff;
}

body.dollarbets-dark-mode .dollarbets-user-modal .stat-item {
    background: #3c3c3c;
}

body.dollarbets-dark-mode .dollarbets-user-modal .stat-value {
    color: #fff;
}

body.dollarbets-dark-mode .dollarbets-user-modal .stat-label {
    color: #ccc;
}

body.dark-mode .dollarbets-user-modal .modal-close {
    color: #ccc;
}

body.dark-mode .dollarbets-user-modal .modal-close:hover {
    color: #fff;
}
`;

// Inject additional CSS
const style = document.createElement('style');
style.textContent = additionalCSS;
document.head.appendChild(style);

