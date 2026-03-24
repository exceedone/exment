namespace Exment {
    export interface FAQ {
        question: string;
        [key: string]: any;
    }
    export interface UItext {
        text_key: string;
        text_value: string;
    }
    export interface ChatHistoryItem {
        question: string;
        answer: string;
    }

    export class Chatbot {
        private STORAGE_CHAT_SCROLL = "chat_scroll";
        private STORAGE_CHAT_HISTORY = "chat_history";
        private STORAGE_CHAT_HISTORY_UPDATE = "chat_history_update";
        private CLASS_MSG_USER = "message msg-user";
        private CLASS_MSG_BOT = "message msg-bot";
        private CLASS_MSG_BOT_THINKING = "message msg-bot thinking";
        private CLASS_FAQ_BLOCK = "message faq-block";
        private CLASS_FAQ_LIST = "faq-list";
        private ID_CHAT_WINDOW = "chatWindow";
        private ID_CHAT_BODY = "chatBody";
        private ID_USER_INPUT = "userInput";
        private ID_TOTOP = "totop";

        private chatbotConfig: { ui_texts?: UItext[] } = {};
        private lastInteractionTime: number = Date.now();
        private inactivityCheckInterval: number | null = null;
        private FAQs: FAQ[] = [];
        private chatHistory: ChatHistoryItem[] = [];

        constructor() {
            window.addEventListener("DOMContentLoaded", () => {
                if (document.getElementById(this.ID_CHAT_WINDOW)) {
                    this.styleIconToTop();
                    this.fetchFAQs();
                    this.loadChatHistoryFromLocal();
                    this.renderChatHistory();
                    this.rebindFAQEvents();
                    this.setLanguage("ja");
                    setInterval(() => this.fetchFAQs(), 10000);
                }
                document.querySelectorAll('.toggleChat').forEach(el => {
                    (el as HTMLElement).onclick = () => this.toggleChat();
                });
                document.querySelectorAll('.expandChat').forEach(el => {
                    (el as HTMLElement).onclick = () => this.expandChat();
                });
                document.querySelectorAll('.sendMessage').forEach(el => {
                    (el as HTMLElement).onclick = () => this.sendMessage();
                });
                // Bind keypress for userInput
                const userInput = document.getElementById(this.ID_USER_INPUT);
                if (userInput) {
                    userInput.addEventListener('keypress', (event) => this.checkEnter(event as KeyboardEvent));
                }
            });
            window.addEventListener('storage', (event) => {
                if (event.key === this.STORAGE_CHAT_HISTORY || event.key === this.STORAGE_CHAT_HISTORY_UPDATE) {
                    this.loadChatHistoryFromLocal();
                    this.renderChatHistory();
                }
            });
        }

        private fetchFAQs(): void {
            fetch((window as any).ExmentChatbot.urls.faq)
                .then((res) => res.json())
                .then((json) => {
                    if (json && Array.isArray(json)) {
                        this.FAQs = json;
                        this.updateFAQListUI();
                    }
                })
                .catch((err) => {
                    console.error("Failed to load FAQs:", err);
                });
        }

        private updateFAQListUI(): void {
            const faqBlock = document.querySelector(`.${this.CLASS_FAQ_BLOCK} .${this.CLASS_FAQ_LIST}`);
            if (faqBlock) {
                faqBlock.innerHTML = "";
                this.FAQs.forEach((q) => {
                    const item = document.createElement("li");
                    item.textContent = q.question;
                    item.onclick = () => this.autoSend(q);
                    faqBlock.appendChild(item);
                });
            }
        }

        private async setLanguage(lang: string): Promise<void> {
            try {
                const res = await fetch((window as any).ExmentChatbot.urls.config);
                if (!res.ok) throw new Error(`Failed to fetch: ${res.status}`);
                this.chatbotConfig = await res.json();
                this.updateTexts();
            } catch (err) {
                console.warn(` Error loading language file "${lang}":`, err);
            }
        }

        private updateTexts(): void {
            if (this.chatbotConfig.ui_texts && Array.isArray(this.chatbotConfig.ui_texts)) {
                const textMap: { [key: string]: string } = {};
                this.chatbotConfig.ui_texts.forEach(item => {
                    textMap[item.text_key] = item.text_value;
                });
                document.querySelectorAll("[data-i18n]").forEach((el) => {
                    const key = el.getAttribute("data-i18n");
                    if (key && textMap[key]) el.textContent = textMap[key];
                });
                document.querySelectorAll("[data-i18n-placeholder]").forEach((el) => {
                    const key = el.getAttribute("data-i18n-placeholder");
                    if (key && textMap[key]) (el as HTMLInputElement).placeholder = textMap[key];
                });
            }
        }

        public expandChat(): void {
            const win = document.getElementById(this.ID_CHAT_WINDOW);
            if (win) win.classList.toggle("expanded");
        }

        private renderFAQList(chatBody: HTMLElement): void {
            const welcome = document.createElement("div");
            welcome.className = this.CLASS_MSG_BOT;
            welcome.textContent = this.getI18nText('welcome', 'Welcome! How can I assist you today?');
            chatBody.appendChild(welcome);
            const faqContainer = document.createElement("div");
            faqContainer.className = this.CLASS_FAQ_BLOCK;
            const faqList = document.createElement("ul");
            faqList.className = this.CLASS_FAQ_LIST;
            faqContainer.appendChild(faqList);
            chatBody.appendChild(faqContainer);
        }

        private autoSend(q: FAQ | string): void {
            const text = typeof q === 'string' ? q : q.question;
            const input = document.getElementById(this.ID_USER_INPUT) as HTMLInputElement;
            if (input) input.value = text;
            this.sendMessage();
        }

        public async sendMessage(): Promise<void> {
            const input = document.getElementById(this.ID_USER_INPUT) as HTMLInputElement;
            if (!input) return;
            const msg = input.value.trim();
            if (!msg) return;
            const chatBody = document.getElementById(this.ID_CHAT_BODY);
            if (!chatBody) return;
            const userDiv = document.createElement("div");
            userDiv.className = this.CLASS_MSG_USER;
            userDiv.textContent = msg;
            chatBody.appendChild(userDiv);
            input.value = "";
            chatBody.scrollTop = chatBody.scrollHeight;
            this.lastInteractionTime = Date.now();
            const thinkingDiv = document.createElement("div");
            thinkingDiv.className = this.CLASS_MSG_BOT_THINKING;
            thinkingDiv.textContent = this.getI18nText('thinking', '...');
            chatBody.appendChild(thinkingDiv);
            chatBody.scrollTop = chatBody.scrollHeight;
            this.loadChatHistoryFromLocal();
            const botReply = await this.fakeCallAPI(msg);
            thinkingDiv.className = this.CLASS_MSG_BOT;
            thinkingDiv.textContent = botReply;
            chatBody.scrollTop = chatBody.scrollHeight;
            this.chatHistory.push({ question: msg, answer: botReply });
            this.saveChatHistoryToLocal();
        }

        private fakeCallAPI(userMessage: string): Promise<string> {
            return new Promise((resolve) => {
                setTimeout(() => {
                    resolve(
                        `8am`
                    );
                }, 2000);
            });
        }

        private async callAPIServer(userMessage: string): Promise<string> {
            try {
                const res = await fetch((window as any).ExmentChatbot.urls.ask, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ question: userMessage })
                });
                if (!res.ok) throw new Error('Failed to get response from AI server');
                const data = await res.json();
                return data.answer || 'Sorry, I did not understand that.';
            } catch (err) {
                console.error('callAPIServer error:', err);
                return 'Sorry, there was a problem contacting the server.';
            }
        }

        public checkEnter(event: KeyboardEvent): void {
            if (event.key === "Enter") {
                this.sendMessage();
            }
        }

        private startInactivityCheck(): void {
            if (this.inactivityCheckInterval) clearInterval(this.inactivityCheckInterval);
            this.inactivityCheckInterval = window.setInterval(() => {
                const now = Date.now();
                const diff = now - this.lastInteractionTime;
                if (diff > 3 * 1000) {
                    this.showInactivityMessage();
                    if (this.inactivityCheckInterval) clearInterval(this.inactivityCheckInterval);
                }
            }, 1 * 1000);
        }

        private showInactivityMessage(): void {
            const chatBody = document.getElementById(this.ID_CHAT_BODY);
            if (!chatBody) return;
            const msg = document.createElement("div");
            msg.className = this.CLASS_MSG_BOT;
            msg.textContent = this.getI18nText('inactivity_question', 'Can I help you with anything else?');
            chatBody.appendChild(msg);
            const buttonWrapper = document.createElement("div");
            buttonWrapper.className = this.CLASS_MSG_BOT + " options";
            const yesBtn = document.createElement("button");
            yesBtn.textContent = this.getI18nText('button_yes', 'Yes');
            yesBtn.onclick = () => {
                this.lastInteractionTime = Date.now();
                buttonWrapper.remove();
                const followup = document.createElement("div");
                followup.className = this.CLASS_MSG_BOT;
                followup.textContent = this.getI18nText('inactivity_followup', 'Sure! Please type your question below.');
                chatBody.appendChild(followup);
                chatBody.scrollTop = chatBody.scrollHeight;
                this.startInactivityCheck();
            };
            const noBtn = document.createElement("button");
            noBtn.textContent = this.getI18nText('button_no', 'No');
            noBtn.onclick = () => {
                buttonWrapper.remove();
                const bye = document.createElement("div");
                bye.className = this.CLASS_MSG_BOT;
                bye.textContent = this.getI18nText('inactivity_bye', 'Thank you for using our service!');
                chatBody.appendChild(bye);
                chatBody.scrollTop = chatBody.scrollHeight;
            };
            buttonWrapper.appendChild(yesBtn);
            buttonWrapper.appendChild(noBtn);
            chatBody.appendChild(buttonWrapper);
            chatBody.scrollTop = chatBody.scrollHeight;
        }

        public toggleChat(): void {
            const win = document.getElementById(this.ID_CHAT_WINDOW);
            const body = document.getElementById(this.ID_CHAT_BODY);
            if (!win || !body) return;
            const isVisible = win.classList.contains("visible");
            if (isVisible) {
                win.classList.remove("visible");
            } else {
                if (!body.innerHTML || body.innerHTML.trim() === "") {
                    this.renderChatHistory();
                }
                const savedScroll = localStorage.getItem(this.STORAGE_CHAT_SCROLL);
                if (savedScroll) {
                    body.scrollTop = parseInt(savedScroll, 10);
                }
                win.classList.add("visible");
                this.startInactivityCheck();
            }
        }

        private styleIconToTop(): void {
            const el = document.getElementById(this.ID_TOTOP);
            if (el) el.style.bottom = "10px";
        }

        private rebindFAQEvents(): void {
            document.querySelectorAll(`.${this.CLASS_FAQ_LIST} li`).forEach((item) => {
                item.addEventListener('click', () => this.autoSend(item.textContent || ""));
            });
        }

        private getI18nText(key: string, fallback: string): string {
            if (this.chatbotConfig.ui_texts && Array.isArray(this.chatbotConfig.ui_texts)) {
                const found = this.chatbotConfig.ui_texts.find(item => item.text_key === key);
                if (found && found.text_value) return found.text_value;
            }
            return fallback;
        }

        private saveChatHistoryToLocal(): void {
            localStorage.setItem(this.STORAGE_CHAT_HISTORY, JSON.stringify(this.chatHistory));
            localStorage.setItem(this.STORAGE_CHAT_HISTORY_UPDATE, Date.now().toString());
        }

        private loadChatHistoryFromLocal(): void {
            const saved = localStorage.getItem(this.STORAGE_CHAT_HISTORY);
            if (saved) {
                this.chatHistory = JSON.parse(saved);
            } else {
                this.chatHistory = [];
            }
        }

        private renderChatHistory(): void {
            const chatBody = document.getElementById(this.ID_CHAT_BODY);
            if (!chatBody) return;
            chatBody.innerHTML = "";
            this.renderFAQList(chatBody);
            this.updateFAQListUI();
            this.chatHistory.forEach(item => {
                const userDiv = document.createElement("div");
                userDiv.className = this.CLASS_MSG_USER;
                userDiv.textContent = item.question;
                chatBody.appendChild(userDiv);
                const botDiv = document.createElement("div");
                botDiv.className = this.CLASS_MSG_BOT;
                botDiv.textContent = item.answer;
                chatBody.appendChild(botDiv);
            });
            chatBody.addEventListener("scroll", () => {
                localStorage.setItem(this.STORAGE_CHAT_SCROLL, chatBody.scrollTop.toString());
            });
            const savedScroll = localStorage.getItem(this.STORAGE_CHAT_SCROLL);
            if (savedScroll) {
                chatBody.scrollTop = parseInt(savedScroll, 10);
            } else {
                chatBody.scrollTop = chatBody.scrollHeight;
            }
        }
    }
}

export default Exment.Chatbot; 