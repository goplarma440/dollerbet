document.addEventListener('DOMContentLoaded', function () {
  const { useState, useEffect } = React;

  function PredictionApp() {
    const [predictions, setPredictions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [activeBet, setActiveBet] = useState(null);
    const [remainingCoins, setRemainingCoins] = useState(null);
    const [inputValue, setInputValue] = useState('');
    const [isLoggedIn, setIsLoggedIn] = useState(dollarbetsConfig.isLoggedIn);
    const [notice, setNotice] = useState(null);
    const [selectedCategory, setSelectedCategory] = useState('All');
    const [categories, setCategories] = useState(['All']);
    const [showInsufficientFunds, setShowInsufficientFunds] = useState(null);
    const [isDarkMode, setIsDarkMode] = useState(false);

    // Load dark mode preference from localStorage on component mount
    useEffect(() => {
      const savedDarkMode = localStorage.getItem('dollarbets-dark-mode');
      if (savedDarkMode !== null) {
        setIsDarkMode(JSON.parse(savedDarkMode));
      }
    }, []);

    // Listen for dark mode changes from header toggle
    useEffect(() => {
      const handleDarkModeChange = (event) => {
        setIsDarkMode(event.detail.isDarkMode);
      };

      // Listen for the custom event from the header toggle
      window.addEventListener('dollarbets-dark-mode-changed', handleDarkModeChange);

      // Also listen for localStorage changes (in case of multiple tabs)
      const handleStorageChange = (event) => {
        if (event.key === 'dollarbets-dark-mode') {
          setIsDarkMode(JSON.parse(event.newValue));
        }
      };

      window.addEventListener('storage', handleStorageChange);

      return () => {
        window.removeEventListener('dollarbets-dark-mode-changed', handleDarkModeChange);
        window.removeEventListener('storage', handleStorageChange);
      };
    }, []);

    // Save dark mode preference to localStorage whenever it changes
    useEffect(() => {
      localStorage.setItem('dollarbets-dark-mode', JSON.stringify(isDarkMode));
      
      // Apply dark mode to document body for WordPress integration
      if (isDarkMode) {
        document.body.classList.add('dollarbets-dark-mode');
      } else {
        document.body.classList.remove('dollarbets-dark-mode');
      }

      // Update the header toggle if it exists
      const headerToggle = document.getElementById('dollarbets-dark-toggle');
      if (headerToggle) {
        headerToggle.checked = !isDarkMode; // Inverted because checked = light mode
        
        // Update toggle appearance
        const toggleBg = document.getElementById('toggle-bg');
        const toggleDot = document.getElementById('toggle-dot');
        if (toggleBg && toggleDot) {
          if (isDarkMode) {
            toggleBg.style.backgroundColor = '#4b5563'; // gray-600
            toggleDot.classList.remove('translate-x-6');
          } else {
            toggleBg.style.backgroundColor = '#3b82f6'; // blue-500
            toggleDot.classList.add('translate-x-6');
          }
        }
      }
    }, [isDarkMode]);

    // Expose setDarkMode function to global scope for header toggle
    useEffect(() => {
      window.dollarbetsApp = {
        setDarkMode: setIsDarkMode
      };

      return () => {
        delete window.dollarbetsApp;
      };
    }, []);

    function showNotice(message, type = 'info', targetId = null, duration = 3000) {
      setNotice({ message, type, targetId });
      if (duration > 0) {
        setTimeout(() => setNotice(null), duration);
      }
    }

    useEffect(() => {
      const fetchUserBalance = async () => {
        try {
          const response = await fetch(dollarbetsConfig.restUrl + 'dollarbets/v1/user-balance', {
            method: 'GET',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': dollarbetsConfig.nonce,
            },
            credentials: 'include',
          });

          if (response.status === 200) {
            const data = await response.json();
            if (data?.success && typeof data.balance === 'number') {
              setRemainingCoins(data.balance);
            } else {
              setRemainingCoins(0); // Set to 0 if balance fetch fails or user is not logged in
            }
          } else {
            setRemainingCoins(0); // Set to 0 if balance fetch fails
          }
        } catch (error) {
          console.error("❌ Failed to fetch balance:", error);
          setRemainingCoins(0); // Set to 0 if balance fetch fails
        }
      };

      fetchUserBalance();
    }, []);

    useEffect(() => {
      fetch(dollarbetsConfig.restUrl + 'wp/v2/prediction?per_page=30&_embed')
        .then(res => res.json())
        .then(data => {
          const formatted = data.map(post => {
            const category = post._embedded?.['wp:term']?.[0]?.[0]?.name || 'General';
            const closingDate = post.meta?.closing_date || '';
            const votesYes = parseInt(post.meta?.votes_yes || 0);
            const votesNo = parseInt(post.meta?.votes_no || 0);

            return {
              id: post.id,
              title: post.title.rendered,
              description: post.content.rendered,
              category,
              closingDate,
              votesYes,
              votesNo,
            };
          });

          const allCategories = [...new Set(['All', ...formatted.map(f => f.category)])];
          setCategories(allCategories);
          setPredictions(formatted);
          setLoading(false);
        })
        .catch(err => {
          console.error('❌ Failed to load predictions:', err);
          setLoading(false);
        });
    }, []);

    function showInsufficientFundsModal(amount, balance, predictionId) {
      setShowInsufficientFunds({
        amount,
        balance,
        predictionId
      });
    }

    function handleAddBetCoins() {
      // Open the payment modal
      if (typeof initiatePurchase === 'function') {
        initiatePurchase(0, 0); // Pass 0,0 as initial values, modal will update
      } else {
        console.error('initiatePurchase function is not defined.');
        alert('Payment functionality is not available. Please contact support.');
      }
    }

    function submitBet() {
      const amount = parseInt(inputValue);
      if (!amount || isNaN(amount) || amount <= 0) {
        showNotice('❗ Please enter a valid BetCoin amount.', 'error', activeBet?.id);
        return;
      }
      
      if (!dollarbetsConfig.isLoggedIn) {
        // If not logged in, show Ultimate Member login form
        if (typeof UM !== 'undefined' && typeof UM.modal !== 'undefined') {
          UM.modal.open({ src: 'login' });
        } else {
          // Fallback if UM modal is not available
          showNotice("❗ Please log in to place a bet.", "error", activeBet?.id);
        }
        return;
      }
      
      if (amount > remainingCoins) {
        showInsufficientFundsModal(amount, remainingCoins, activeBet.id);
        return;
      }

      fetch(dollarbetsConfig.restUrl + 'dollarbets/v1/place-bet', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': dollarbetsConfig.nonce
        },
        body: JSON.stringify({
          prediction_id: activeBet.id,
          choice: activeBet.choice,
          amount
        })
      })
        .then(res => res.json())
        .then(response => {
          if (response.success) {
            setRemainingCoins(response.remaining_balance);
            setActiveBet(null);
            setInputValue('');
            showNotice('✅ Bet placed successfully!', 'success', response.prediction_id || activeBet?.id);
          } else {
            showNotice(`❌ ${response.message || 'Something went wrong.'}`, 'error', activeBet?.id);
          }
        })
        .catch(err => {
          console.error('❌ Error placing bet:', err);
          showNotice('⚠️ Network error. Try again.', 'error', activeBet?.id);
        });
    }

    function renderNotice(predId) {
      if (!notice || notice.targetId !== predId) return null;
      const bgMap = {
        success: 'bg-green-100 text-green-800 border border-green-300',
        error: 'bg-red-100 text-red-800 border border-red-300',
        info: 'bg-blue-100 text-blue-800 border border-blue-300',
      };
      return React.createElement(
        'div',
        {
          className: `absolute top-2 left-2 right-2 z-50 px-3 py-2 text-sm rounded shadow-md ${bgMap[notice.type] || bgMap.info}`
        },
        notice.message
      );
    }

    function renderInsufficientFundsOverlay(predId) {
      if (!showInsufficientFunds || showInsufficientFunds.predictionId !== predId) return null;
      
      return React.createElement('div', {
        className: `absolute inset-0 flex flex-col justify-center items-center p-4 rounded-2xl z-20`,
        style: {
          backgroundColor: isDarkMode ? 'rgba(13, 14, 27, 0.95)' : 'rgba(255, 255, 255, 0.95)',
          backdropFilter: 'blur(10px)',
          WebkitBackdropFilter: 'blur(10px)'
        }
      },
        React.createElement('div', {
          className: 'text-center'
        },
          React.createElement('h4', {
            className: 'text-lg font-semibold text-red-500 mb-4'
          }, 'Insufficient BetCoins'),
          React.createElement('p', {
            className: `mb-2 text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`
          }, `Bet Amount: ${showInsufficientFunds.amount.toLocaleString()} BetCoins`),
          React.createElement('p', {
            className: `mb-6 text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`
          }, `Available Balance: ${showInsufficientFunds.balance.toLocaleString()} BetCoins`),
          React.createElement('div', {
            className: 'flex gap-3'
          },
            React.createElement('button', {
              onClick: handleAddBetCoins,
              className: 'px-5 py-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg font-semibold transition-all hover:shadow-lg'
            }, 'Add BetCoins'),
            React.createElement('button', {
              onClick: () => setShowInsufficientFunds(null),
              className: `px-5 py-2 rounded-lg font-semibold transition-colors ${isDarkMode ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'}`
            }, 'Cancel')
          )
        )
      );
    }

    if (loading) {
      return React.createElement('div', { 
        className: `min-h-screen flex items-center justify-center ${isDarkMode ? 'bg-gray-900 text-gray-300' : 'bg-gray-50 text-gray-500'}` 
      }, 'Loading predictions...');
    }

    return React.createElement(
      'div',
      { 
        className: `min-h-screen w-full transition-all duration-300`,
        style: {
          backgroundColor: isDarkMode ? '#0D0E1B' : '#F3F4F6',
          color: isDarkMode ? '#E0E0E0' : '#1F2937',
          margin: '0',
          padding: '0'
        }
      },
      React.createElement(
        React.Fragment,
        {},
        // Header with toggle switch (only show if no header toggle exists)
        !document.getElementById('dollarbets-dark-toggle') && React.createElement('header', { 
          className: 'w-full px-6 py-6 flex justify-end items-center',
          style: { maxWidth: 'none' }
        },
          React.createElement('div', { className: 'flex items-center space-x-4' },
            isLoggedIn && remainingCoins !== null && React.createElement('span', {
              className: 'text-sm font-medium'
            }, `💰 ${remainingCoins.toLocaleString()} BetCoins`),
            React.createElement('label', { 
              className: 'relative inline-block w-12 h-6 cursor-pointer'
            },
              React.createElement('input', {
                type: 'checkbox',
                checked: !isDarkMode,
                onChange: () => setIsDarkMode(!isDarkMode),
                className: 'opacity-0 w-0 h-0'
              }),
              React.createElement('span', {
                className: `absolute inset-0 rounded-full transition-colors duration-300 ${isDarkMode ? 'bg-gray-600' : 'bg-blue-500'}`
              }),
              React.createElement('span', {
                className: `absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full transition-transform duration-300 ${!isDarkMode ? 'translate-x-6' : 'translate-x-0'} z-10`
              })
            )
          )
        ),
        
        // Main content - full width
        React.createElement('main', { 
          className: 'w-full px-6 py-8',
          style: { maxWidth: 'none' }
        },
          // Category filters
          React.createElement('div', { className: 'mb-8 flex flex-wrap justify-center gap-2' },
            categories.map(cat => React.createElement('button', {
              key: cat,
              onClick: () => setSelectedCategory(cat),
              className: `px-6 py-2 text-sm rounded-full font-medium transition-all ${selectedCategory === cat ? 'bg-gradient-to-r from-blue-500 to-purple-600 text-white shadow-lg' : isDarkMode ? 'bg-gray-800 text-gray-300 hover:bg-gray-700 border border-gray-600' : 'bg-white text-gray-700 hover:bg-gray-50 border border-gray-300'}`,
            }, cat))
          ),

          // Predictions grid - full width
          React.createElement('div', {
            className: 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-6 w-full'
          },
            predictions.filter(pred => selectedCategory === 'All' || pred.category === selectedCategory).map(pred => {
              const totalVotes = pred.votesYes + pred.votesNo || 1;
              const yesPercent = Math.round((pred.votesYes / totalVotes) * 100);
              const noPercent = 100 - yesPercent;
              const isActive = activeBet?.id === pred.id;

              return React.createElement('div', {
                key: pred.id,
                className: `relative p-6 rounded-2xl text-left transition-all duration-300 cursor-pointer`,
                style: {
                  backgroundColor: isDarkMode ? 'rgba(30, 32, 56, 0.5)' : '#FFFFFF',
                  border: isDarkMode ? '1px solid rgba(255, 255, 255, 0.1)' : '1px solid #E5E7EB',
                  backdropFilter: 'blur(10px)',
                  WebkitBackdropFilter: 'blur(10px)',
                  transform: 'translateY(0px)',
                  boxShadow: '0 4px 6px rgba(0, 0, 0, 0.1)'
                },
                onMouseEnter: (e) => {
                  e.currentTarget.style.transform = 'translateY(-5px)';
                  e.currentTarget.style.boxShadow = '0 10px 20px rgba(0, 0, 0, 0.2)';
                },
                onMouseLeave: (e) => {
                  e.currentTarget.style.transform = 'translateY(0px)';
                  e.currentTarget.style.boxShadow = '0 4px 6px rgba(0, 0, 0, 0.1)';
                }
              },
                renderNotice(pred.id),
                renderInsufficientFundsOverlay(pred.id),
                React.createElement('h3', {
                  className: `font-bold text-xl mb-4 ${isDarkMode ? 'text-gray-100' : 'text-gray-800'}`,
                  dangerouslySetInnerHTML: { __html: pred.title }
                }),
                React.createElement('div', {
                  className: 'flex items-center justify-between text-sm font-bold mb-1'
                },
                  React.createElement('span', { style: { color: '#3b82f6' } }, `${yesPercent}%`),
                  React.createElement('span', { style: { color: '#d946ef' } }, `${noPercent}%`)
                ),
                React.createElement('div', { className: 'flex h-2.5 w-full rounded-full overflow-hidden mb-2' },
                  React.createElement('div', {
                    style: { 
                      width: yesPercent + '%',
                      background: 'linear-gradient(to right, #3b82f6, #2563eb)'
                    }
                  }),
                  React.createElement('div', {
                    style: { 
                      width: noPercent + '%',
                      background: 'linear-gradient(to right, #d946ef, #9333ea)'
                    }
                  })
                ),
                React.createElement('div', {
                  className: 'flex items-center justify-between text-xs mb-6'
                },
                  React.createElement('span', { 
                    className: isDarkMode ? 'text-gray-400' : 'text-gray-500'
                  }, `${pred.votesYes.toLocaleString()} votes`),
                  React.createElement('span', { 
                    className: isDarkMode ? 'text-gray-400' : 'text-gray-500'
                  }, `${pred.votesNo.toLocaleString()} votes`)
                ),
                React.createElement('div', { 
                  className: `text-xs mb-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}` 
                }, 'Category: ' + pred.category),
                React.createElement('div', { 
                  className: `text-xs mb-4 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}` 
                }, 'Closes: ' + pred.closingDate),
                React.createElement('div', { className: 'grid grid-cols-2 gap-4' },
                  React.createElement('button', {
                    className: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors',
                    onClick: () => {
                      setActiveBet({ id: pred.id, choice: 'yes', title: pred.title });
                      setInputValue('');
                      setShowInsufficientFunds(null);
                    }
                  }, 'YES'),
                  React.createElement('button', {
                    className: 'bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg transition-colors',
                    onClick: () => {
                      setActiveBet({ id: pred.id, choice: 'no', title: pred.title });
                      setInputValue('');
                      setShowInsufficientFunds(null);
                    }
                  }, 'NO')
                ),
                isActive && React.createElement('div', {
                  className: `absolute inset-0 flex flex-col justify-center items-center p-4 rounded-2xl z-10`,
                  style: {
                    backgroundColor: isDarkMode ? 'rgba(13, 14, 27, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                    backdropFilter: 'blur(10px)',
                    WebkitBackdropFilter: 'blur(10px)'
                  }
                },
                  React.createElement('h4', { 
                    className: `text-sm mb-3 font-semibold text-center ${isDarkMode ? 'text-gray-100' : 'text-gray-800'}` 
                  }, `Place Bet on "${activeBet.title}"`),
                  isLoggedIn && remainingCoins !== null && React.createElement('div', {
                    className: `text-xs mb-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`
                  }, `Available: ${remainingCoins.toLocaleString()} BetCoins`),
                  React.createElement('input', {
                    type: 'number',
                    value: inputValue,
                    placeholder: 'Enter coins',
                    onChange: e => setInputValue(e.target.value),
                    className: `px-3 py-2 rounded-lg w-36 mb-3 text-center transition-colors ${isDarkMode ? 'border-2 border-gray-600 bg-gray-800 text-gray-100 focus:border-blue-500' : 'border-2 border-gray-300 bg-white text-gray-800 focus:border-blue-500'}`
                  }),
                  React.createElement('div', { className: 'flex gap-3' },
                    React.createElement('button', {
                      onClick: submitBet,
                      className: 'bg-gradient-to-r from-blue-500 to-purple-600 text-white text-sm px-4 py-2 rounded-lg font-semibold transition-all hover:shadow-lg'
                    }, 'Submit'),
                    React.createElement('button', {
                      onClick: () => setActiveBet(null),
                      className: `text-sm px-4 py-2 rounded-lg font-semibold transition-colors ${isDarkMode ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'}`
                    }, 'Cancel')
                  )
                )
              );
            })
          )
        )
      )
    );
  }

  const rootEl = document.getElementById('dollarbets-root');
  if (rootEl && ReactDOM && ReactDOM.createRoot) {
    const root = ReactDOM.createRoot(rootEl);
    root.render(React.createElement(PredictionApp));
  }
});