(function () {
    const config = window.dollarbetsConfig || {};
    const NEWS_API_KEY = config.newsApiKey;
    const REST_BASE = config.restUrl;
    const PREDICTION_ENDPOINT = `${REST_BASE}dollarbets/v1/predictions`;
    const BALANCE_ENDPOINT = `${REST_BASE}dollarbets/v1/user-balance`;
    const MAX_PER_DAY = 10;
    const categories = ['Football', 'Basketball', 'Elections'];

    let createdToday = 0;

    const DollarBetsContentManager = {
        async init() {
            if (!NEWS_API_KEY) {
                console.warn("No NewsAPI key provided");
                return;
            }

            const articles = await this.fetchNews();
            const predictions = this.generatePredictions(articles);
            this.renderPredictions(predictions.slice(0, MAX_PER_DAY));
        },

        async fetchNews() {
            try {
                const url = `https://newsapi.org/v2/top-headlines?language=en&pageSize=30&apiKey=${NEWS_API_KEY}`;
                const response = await fetch(url);
                const data = await response.json();
                return data.articles || [];
            } catch (error) {
                console.error("❌ Error fetching news", error);
                return [];
            }
        },

        generatePredictions(articles) {
            const predictions = [];

            articles.forEach((article) => {
                const title = article.title?.trim() || '';
                const description = article.description?.trim() || '';
                const fullText = `${title} ${description}`.toLowerCase();

                const category = categories.find(cat => fullText.includes(cat.toLowerCase())) || 'General';
                const question = this.generateQuestion(title);
                const closingDate = this.randomFutureDate();

                if (question && description.length > 10) {
                    predictions.push({
                        title: question,
                        content: description,
                        category,
                        closing_date: closingDate
                    });
                }
            });

            return predictions;
        },

        generateQuestion(text) {
            if (!text || text.length < 10) return null;
            if (text.includes('Will')) return text;
            if (text.includes('Can')) return text.replace('Can', 'Will');
            return `Will ${text.replace(/\.$/, '')}?`;
        },

        randomFutureDate() {
            const today = new Date();
            const future = new Date(today.getTime() + Math.floor(Math.random() * 10 + 5) * 86400000);
            return future.toISOString().split('T')[0];
        },

        renderPredictions(predictions) {
            const container = document.getElementById('predictions-container');
            if (!container) return;

            predictions.forEach((prediction, index) => {
                const card = document.createElement('div');
                card.className = 'prediction-card p-4 border rounded mb-4 bg-white dark:bg-gray-800 text-black dark:text-white';

                card.innerHTML = `
                    <h3 class="text-lg font-semibold mb-2">${prediction.title}</h3>
                    <p class="mb-2 text-sm">${prediction.content}</p>
                    <p class="text-xs text-gray-500 mb-2">Category: ${prediction.category} | Closes: ${prediction.closing_date}</p>
                    <input type="number" placeholder="Enter BetCoin amount" class="bet-amount border px-2 py-1 text-black" />
                    <button data-index="${index}" class="submit-bet mt-2 bg-blue-500 text-white px-4 py-1 rounded">Place Bet</button>
                `;

                container.appendChild(card);
            });

            // Add event listener after rendering
            document.querySelectorAll('.submit-bet').forEach(button => {
                button.addEventListener('click', async (e) => {
                    const index = e.target.getAttribute('data-index');
                    const amountInput = e.target.parentElement.querySelector('.bet-amount');
                    const amount = parseInt(amountInput.value);

                    if (!amount || amount <= 0) {
                        alert("Please enter a valid BetCoin amount");
                        return;
                    }

                    const isLoggedIn = await DollarBetsContentManager.checkUserAuth();

                    if (!isLoggedIn) {
                        // Redirect using dynamic login URL passed from WordPress
                        const loginUrl = (typeof DollarBetsConfig !== 'undefined' && DollarBetsConfig.loginUrl)
                            ? DollarBetsConfig.loginUrl
                            : '/login/';

                        window.location.href = loginUrl;
                        return;
                    }


                    // 🔥 Submit bet via your API (replace with real API)
                    console.log(`Placing bet of ${amount} on:`, predictions[index].title);
                    alert(`✅ Bet placed for "${predictions[index].title}" with ${amount} BetCoins`);
                });
            });
        },

        async checkUserAuth() {
            try {
                const res = await fetch(BALANCE_ENDPOINT, {
                    credentials: 'include',
                    headers: { 'X-WP-Nonce': config.nonce }
                });
                return res.ok;
            } catch (err) {
                console.error("❌ Error checking user auth:", err);
                return false;
            }
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        DollarBetsContentManager.init();
    });
})();
