var STORAGE_CHAT_SCROLL = "chat_scroll";
var STORAGE_CHAT_HISTORY = "chat_history";
var STORAGE_CHAT_HISTORY_UPDATE = "chat_history_update";
var CLASS_MSG_USER = "message msg-user";
var CLASS_MSG_BOT = "message msg-bot";
var CLASS_MSG_BOT_THINKING = "message msg-bot thinking";
var CLASS_FAQ_BLOCK = "message faq-block";
var CLASS_FAQ_LIST = "faq-list";
var ID_CHAT_WINDOW = "chatWindow";
var ID_CHAT_BODY = "chatBody";
var ID_USER_INPUT = "userInput";
var ID_TOTOP = "totop";

let chatbotConfig = {};
let lastInteractionTime = Date.now();
let inactivityCheckInterval = null;
let FAQs = [];
let chatHistory = [];

// Fetch FAQs from API and update the UI
function fetchFAQs() {
    fetch("/api/chatbot/faq")
        .then((res) => res.json())
        .then((json) => {
            if (json && Array.isArray(json)) {
                FAQs = json;
                updateFAQListUI();
            }
        })
        .catch((err) => {
            console.error("Failed to load FAQs:", err);
        });
}

// Update the FAQ list in the UI
function updateFAQListUI() {
    const faqBlock = document.querySelector(".faq-block .faq-list");
    if (faqBlock) {
        faqBlock.innerHTML = "";
        FAQs.forEach((q) => {
            const item = document.createElement("li");
            item.textContent = q.question;
            item.onclick = () => autoSend(q);
            faqBlock.appendChild(item);
        });
    }
}

// Set language and update UI texts
async function setLanguage(lang) {
    try {
        const res = await fetch(`/api/chatbot/config`);
        if (!res.ok) throw new Error(`Failed to fetch: ${res.status}`);
        chatbotConfig = await res.json();
        updateTexts();
    } catch (err) {
        console.warn(` Error loading language file "${lang}":`, err);
    }
}

// Update UI texts based on chatbotConfig
function updateTexts() {
    if (chatbotConfig.ui_texts && Array.isArray(chatbotConfig.ui_texts)) {
        const textMap = {};
        chatbotConfig.ui_texts.forEach((item) => {
            textMap[item.text_key] = item.text_value;
        });
        document.querySelectorAll("[data-i18n]").forEach((el) => {
            const key = el.getAttribute("data-i18n");
            if (textMap[key]) el.textContent = textMap[key];
        });
        document.querySelectorAll("[data-i18n-placeholder]").forEach((el) => {
            const key = el.getAttribute("data-i18n-placeholder");
            if (textMap[key]) el.placeholder = textMap[key];
        });
    }
}

// Expand or collapse the chat window
function expandChat() {
    const win = document.getElementById(ID_CHAT_WINDOW);
    win.classList.toggle("expanded");
}

function renderFAQList(chatBody) {
    const welcome = document.createElement("div");
    welcome.className = CLASS_MSG_BOT;
    welcome.setAttribute("data-i18n", "welcome");
    welcome.textContent = getI18nText(
        "welcome",
        "Welcome! How can I assist you today?"
    );
    chatBody.appendChild(welcome);
    const faqContainer = document.createElement("div");
    faqContainer.className = CLASS_FAQ_BLOCK;
    const faqList = document.createElement("ul");
    faqList.className = CLASS_FAQ_LIST;
    faqContainer.appendChild(faqList);
    chatBody.appendChild(faqContainer);
}

// Auto-send a selected FAQ question
function autoSend(q) {
    const text = typeof q === "string" ? q : q.question;
    document.getElementById(ID_USER_INPUT).value = text;
    sendMessage();
}

// Send user message and handle bot response
async function sendMessage() {
    const input = document.getElementById(ID_USER_INPUT);
    const msg = input.value.trim();
    if (!msg) return;
    const chatBody = document.getElementById(ID_CHAT_BODY);
    const userDiv = document.createElement("div");
    userDiv.className = CLASS_MSG_USER;
    userDiv.textContent = msg;
    chatBody.appendChild(userDiv);
    input.value = "";
    chatBody.scrollTop = chatBody.scrollHeight;
    lastInteractionTime = Date.now();
    const thinkingDiv = document.createElement("div");
    thinkingDiv.className = CLASS_MSG_BOT_THINKING;
    thinkingDiv.textContent = getI18nText("thinking", "...");
    chatBody.appendChild(thinkingDiv);
    chatBody.scrollTop = chatBody.scrollHeight;
    // Always reload latest history before pushing
    loadChatHistoryFromLocal();
    const botReply = await callAPIServer(msg);
    thinkingDiv.className = CLASS_MSG_BOT;
    thinkingDiv.textContent = botReply;
    chatBody.scrollTop = chatBody.scrollHeight;
    // Push to history and save
    chatHistory.push({ question: msg, answer: botReply });
    saveChatHistoryToLocal();
}

// Call API server
async function callAPIServer(userMessage) {
    try {
        const res = await fetch("/api/chatbot/ask", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ question: userMessage, _token: LA.token }),
        });
        if (!res.ok) throw new Error("Failed to get response from AI server");
        const data = await res.json();
        return data.answer || "Sorry, I did not understand that.";
    } catch (err) {
        console.error("callAPIServer error:", err);
        return "Sorry, there was a problem contacting the server.";
    }
}

// Handle Enter key for sending message
function checkEnter(event) {
    if (event.key === "Enter") {
        sendMessage();
    }
}

// Start inactivity check timer
function startInactivityCheck() {
    if (inactivityCheckInterval) clearInterval(inactivityCheckInterval);
    inactivityCheckInterval = setInterval(() => {
        const now = Date.now();
        const diff = now - lastInteractionTime;
        const timeout = chatbotConfig.timeidle || 10; // Default to 10 minutes if not set
        if (diff > timeout * 60 * 1000) {
            showInactivityMessage();
            clearInterval(inactivityCheckInterval);
        }
    }, 60 * 1000);
}

// Show inactivity message and options
function showInactivityMessage() {
    const chatBody = document.getElementById(ID_CHAT_BODY);
    const msg = document.createElement("div");
    msg.className = CLASS_MSG_BOT;
    msg.textContent = getI18nText(
        "inactivity_question",
        "Can I help you with anything else?"
    );
    chatBody.appendChild(msg);
    const buttonWrapper = document.createElement("div");
    buttonWrapper.className = CLASS_MSG_BOT + " options";
    const yesBtn = document.createElement("button");
    yesBtn.textContent = getI18nText("button_yes", "Yes");
    yesBtn.onclick = () => {
        lastInteractionTime = Date.now();
        buttonWrapper.remove();
        const followup = document.createElement("div");
        followup.className = CLASS_MSG_BOT;
        followup.textContent = getI18nText(
            "followup_message",
            "Sure! Please type your question below."
        );
        chatBody.appendChild(followup);
        chatBody.scrollTop = chatBody.scrollHeight;
        startInactivityCheck();
    };
    const noBtn = document.createElement("button");
    noBtn.textContent = getI18nText("button_no", "No");
    noBtn.onclick = () => {
        buttonWrapper.remove();
        const bye = document.createElement("div");
        bye.className = CLASS_MSG_BOT;
        bye.textContent = getI18nText(
            "bye_message",
            "Thank you for using our service!"
        );
        chatBody.appendChild(bye);
        chatBody.scrollTop = chatBody.scrollHeight;
    };
    buttonWrapper.appendChild(yesBtn);
    buttonWrapper.appendChild(noBtn);
    chatBody.appendChild(buttonWrapper);
    chatBody.scrollTop = chatBody.scrollHeight;
}

// Toggle chat window visibility
function toggleChat() {
    const win = document.getElementById(ID_CHAT_WINDOW);
    const body = document.getElementById(ID_CHAT_BODY);
    const isVisible = win.classList.contains("visible");
    if (isVisible) {
        win.classList.remove("visible");
    } else {
        if (!body.innerHTML || body.innerHTML.trim() === "") {
            renderChatHistory();
        }
        // Restore scroll position when opening chat
        const savedScroll = localStorage.getItem(STORAGE_CHAT_SCROLL);
        if (savedScroll) {
            body.scrollTop = parseInt(savedScroll, 10);
        }
        win.classList.add("visible");
        startInactivityCheck();
    }
}

// Style the icon to the top
function styleIconToTop() {
    document.getElementById(ID_TOTOP).style.bottom = "10px";
}

// Rebind FAQ click events
function rebindFAQEvents() {
    document.querySelectorAll(".faq-list li").forEach((item) => {
        item.onclick = () => autoSend(item.textContent);
    });
}

// Helper to get i18n text by key with fallback
function getI18nText(key, fallback) {
    console.log("chatbotConfig.ui_texts:", chatbotConfig.ui_texts);
    if (chatbotConfig.ui_texts && Array.isArray(chatbotConfig.ui_texts)) {
        const found = chatbotConfig.ui_texts.find(
            (item) => item.text_key === key
        );
        if (found && found.text_value) return found.text_value;
        console.warn(
            `getI18nText: key '${key}' not found in chatbotConfig.ui_texts`
        );
    } else {
        console.warn(
            "getI18nText: chatbotConfig.ui_texts is not available or not an array"
        );
    }
    return fallback;
}

function saveChatHistoryToLocal() {
    localStorage.setItem(STORAGE_CHAT_HISTORY, JSON.stringify(chatHistory));
    // Trigger storage event for other tabs
    localStorage.setItem(STORAGE_CHAT_HISTORY_UPDATE, Date.now().toString());
}

function loadChatHistoryFromLocal() {
    const saved = localStorage.getItem(STORAGE_CHAT_HISTORY);
    if (saved) {
        chatHistory = JSON.parse(saved);
    } else {
        chatHistory = [];
    }
}

function renderChatHistory() {
    const chatBody = document.getElementById(ID_CHAT_BODY);
    if (!chatBody) return;
    chatBody.innerHTML = "";
    renderFAQList(chatBody);
    updateFAQListUI();
    chatHistory.forEach((item) => {
        const userDiv = document.createElement("div");
        userDiv.className = CLASS_MSG_USER;
        userDiv.textContent = item.question;
        chatBody.appendChild(userDiv);
        const botDiv = document.createElement("div");
        botDiv.className = CLASS_MSG_BOT;
        botDiv.textContent = item.answer;
        chatBody.appendChild(botDiv);
    });
    // Always track scroll position
    chatBody.addEventListener("scroll", () => {
        localStorage.setItem(STORAGE_CHAT_SCROLL, chatBody.scrollTop);
    });
    // Restore scroll position if available
    const savedScroll = localStorage.getItem(STORAGE_CHAT_SCROLL);
    if (savedScroll) {
        chatBody.scrollTop = parseInt(savedScroll, 10);
    } else {
        chatBody.scrollTop = chatBody.scrollHeight;
    }
}

// Listen for storage changes from other tabs
window.addEventListener("storage", (event) => {
    if (
        event.key === STORAGE_CHAT_HISTORY ||
        event.key === STORAGE_CHAT_HISTORY_UPDATE
    ) {
        loadChatHistoryFromLocal();
        renderChatHistory();
    }
});

window.addEventListener("DOMContentLoaded", () => {
    var hasChatIcon = document.querySelector(".chat-icon.toggleChat");
    var hasChatWindow = document.getElementById(ID_CHAT_WINDOW);
    if (!hasChatIcon && !hasChatWindow) {
        // Tìm thẻ footer
        var footer = document.querySelector("footer");
        if (footer) {
            var chatHTML = `
<div class="chat-icon toggleChat">💬</div>
<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <div data-i18n="header">Chatbot Assistant</div>
        <div class="icons">
            <span class="expandChat">↗️</span>
            <span class="toggleChat">❌</span>
        </div>
    </div>
    <div class="chat-body" id="chatBody"></div>
    <div class="chat-footer">
        <input type="text" id="userInput" placeholder="Type your question..." data-i18n-placeholder="placeholder_question">
        <button class="sendMessage" data-i18n="button_send">Send</button>
    </div>
</div>
            `;
            footer.insertAdjacentHTML("beforeend", chatHTML);
        }
    }
    if (document.getElementById(ID_CHAT_WINDOW)) {
        styleIconToTop();
        fetchFAQs();
        loadChatHistoryFromLocal();
        renderChatHistory();
        setLanguage("ja");
    }

    document.querySelectorAll(".toggleChat").forEach((el) => {
        el.onclick = () => this.toggleChat();
    });
    document.querySelectorAll(".expandChat").forEach((el) => {
        el.onclick = () => this.expandChat();
    });
    document.querySelectorAll(".sendMessage").forEach((el) => {
        el.onclick = () => this.sendMessage();
    });
    const userInput = document.getElementById(ID_USER_INPUT);
    if (userInput) {
        userInput.addEventListener("keypress", (event) =>
            this.checkEnter(event)
        );
    }
});
