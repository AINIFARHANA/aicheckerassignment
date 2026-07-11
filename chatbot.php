<?php
/**
 * AI Assignment Checker — Chatbot Component
 * 
 * Include this file in any PHP page:
 *   <?php include 'includes/chatbot.php'; ?>
 * 
 * No database, no external API, no AJAX required.
 * Fully self-contained with HTML, CSS, and JavaScript.
 */
?>

<!-- ============================================================
     AI CHATBOT — STYLES
     ============================================================ -->
<style>
    /* --- Scope all styles under .acbot-root --- */
    .acbot-root,
    .acbot-root *,
    .acbot-root *::before,
    .acbot-root *::after {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    /* --- Floating Trigger Button --- */
    .acbot-trigger {
        position: fixed;
        bottom: 28px;
        right: 28px;
        width: 62px;
        height: 62px;
        border-radius: 50%;
        border: none;
        cursor: pointer;
        z-index: 99999;
        background: linear-gradient(135deg, #6A0DAD, #8E44AD);
        color: #fff;
        font-size: 1.55rem;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow:
            0 4px 20px rgba(106, 13, 173, 0.45),
            0 0 0 0 rgba(142, 68, 173, 0.35);
        transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1),
                    box-shadow 0.3s ease;
        outline: none;
    }

    .acbot-trigger:hover {
        transform: scale(1.1);
        box-shadow:
            0 6px 28px rgba(106, 13, 173, 0.55),
            0 0 0 8px rgba(142, 68, 173, 0.08);
    }

    .acbot-trigger:active {
        transform: scale(0.95);
    }

    /* Pulse ring animation */
    .acbot-trigger::before {
        content: '';
        position: absolute;
        inset: -5px;
        border-radius: 50%;
        border: 2px solid rgba(142, 68, 173, 0.5);
        animation: acbotPulse 2.2s ease-out infinite;
        pointer-events: none;
    }

    .acbot-trigger::after {
        content: '';
        position: absolute;
        inset: -5px;
        border-radius: 50%;
        border: 2px solid rgba(142, 68, 173, 0.35);
        animation: acbotPulse 2.2s 0.6s ease-out infinite;
        pointer-events: none;
    }

    @keyframes acbotPulse {
        0%   { transform: scale(1);   opacity: 0.7; }
        100% { transform: scale(1.7); opacity: 0; }
    }

    /* Hide pulse when chat is open */
    .acbot-root.open .acbot-trigger::before,
    .acbot-root.open .acbot-trigger::after {
        animation: none;
        opacity: 0;
    }

    /* Trigger icon swap */
    .acbot-trigger .acbot-icon-bot,
    .acbot-trigger .acbot-icon-x {
        position: absolute;
        transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1),
                    opacity 0.25s ease;
    }

    .acbot-trigger .acbot-icon-x {
        opacity: 0;
        transform: rotate(-90deg) scale(0.5);
    }

    .acbot-root.open .acbot-trigger .acbot-icon-bot {
        opacity: 0;
        transform: rotate(90deg) scale(0.5);
    }

    .acbot-root.open .acbot-trigger .acbot-icon-x {
        opacity: 1;
        transform: rotate(0deg) scale(1);
    }

    /* Notification badge */
    .acbot-badge {
        position: absolute;
        top: -2px;
        right: -2px;
        width: 20px;
        height: 20px;
        background: #FF3B5C;
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #0D0612;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1),
                    opacity 0.25s ease;
        pointer-events: none;
    }

    .acbot-badge.hidden {
        transform: scale(0);
        opacity: 0;
    }

    /* --- Chat Window --- */
    .acbot-window {
        position: fixed;
        bottom: 100px;
        right: 28px;
        width: 400px;
        max-height: 560px;
        height: calc(100vh - 140px);
        max-height: min(560px, calc(100vh - 140px));
        border-radius: 20px;
        overflow: hidden;
        z-index: 99998;
        display: flex;
        flex-direction: column;
        background: rgba(22, 10, 32, 0.78);
        backdrop-filter: blur(24px) saturate(180%);
        -webkit-backdrop-filter: blur(24px) saturate(180%);
        border: 1px solid rgba(142, 68, 173, 0.18);
        box-shadow:
            0 12px 48px rgba(0, 0, 0, 0.5),
            0 0 0 1px rgba(142, 68, 173, 0.06),
            0 0 80px rgba(106, 13, 173, 0.08);

        /* Closed state */
        transform: translateY(24px) scale(0.92);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                    opacity 0.3s ease,
                    visibility 0s 0.35s;
    }

    .acbot-root.open .acbot-window {
        transform: translateY(0) scale(1);
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                    opacity 0.3s ease,
                    visibility 0s 0s;
    }

    /* --- Header --- */
    .acbot-header {
        flex-shrink: 0;
        padding: 16px 20px;
        background: linear-gradient(135deg, #6A0DAD, #8E44AD);
        display: flex;
        align-items: center;
        gap: 12px;
        position: relative;
    }

    .acbot-header-avatar {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }

    .acbot-header-info {
        flex: 1;
        min-width: 0;
    }

    .acbot-header-name {
        color: #fff;
        font-weight: 700;
        font-size: 0.92rem;
        line-height: 1.2;
    }

    .acbot-header-status {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 0.72rem;
        color: rgba(255, 255, 255, 0.75);
        margin-top: 2px;
    }

    .acbot-status-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #34D399;
        box-shadow: 0 0 6px rgba(52, 211, 153, 0.5);
        animation: acbotStatusPulse 2s ease-in-out infinite;
    }

    @keyframes acbotStatusPulse {
        0%, 100% { opacity: 1; }
        50%      { opacity: 0.4; }
    }

    .acbot-header-close {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        border: none;
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.85rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .acbot-header-close:hover {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }

    /* --- Messages Area --- */
    .acbot-messages {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 20px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    /* Custom scrollbar */
    .acbot-messages::-webkit-scrollbar {
        width: 5px;
    }

    .acbot-messages::-webkit-scrollbar-track {
        background: transparent;
    }

    .acbot-messages::-webkit-scrollbar-thumb {
        background: rgba(142, 68, 173, 0.3);
        border-radius: 10px;
    }

    .acbot-messages::-webkit-scrollbar-thumb:hover {
        background: rgba(142, 68, 173, 0.5);
    }

    /* --- Message Bubbles --- */
    .acbot-msg {
        display: flex;
        gap: 8px;
        max-width: 88%;
        animation: acbotMsgIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both;
    }

    .acbot-msg.user {
        align-self: flex-end;
        flex-direction: row-reverse;
    }

    .acbot-msg.bot {
        align-self: flex-start;
    }

    @keyframes acbotMsgIn {
        from {
            opacity: 0;
            transform: translateY(10px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    /* Message avatar */
    .acbot-msg-avatar {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        margin-top: 2px;
    }

    .acbot-msg.bot .acbot-msg-avatar {
        background: linear-gradient(135deg, #6A0DAD, #8E44AD);
        color: #fff;
    }

    .acbot-msg.user .acbot-msg-avatar {
        background: rgba(142, 68, 173, 0.15);
        color: #C084FC;
    }

    /* Bubble */
    .acbot-bubble {
        padding: 10px 14px;
        border-radius: 14px;
        font-size: 0.82rem;
        line-height: 1.6;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .acbot-msg.bot .acbot-bubble {
        background: rgba(142, 68, 173, 0.12);
        color: rgba(244, 209, 255, 0.88);
        border-bottom-left-radius: 4px;
        border: 1px solid rgba(142, 68, 173, 0.12);
    }

    .acbot-msg.user .acbot-bubble {
        background: linear-gradient(135deg, #6A0DAD, #8E44AD);
        color: #fff;
        border-bottom-right-radius: 4px;
    }

    /* Timestamp */
    .acbot-time {
        font-size: 0.62rem;
        color: rgba(244, 209, 255, 0.3);
        margin-top: 4px;
        padding: 0 4px;
    }

    .acbot-msg.user .acbot-time {
        text-align: right;
    }

    /* Bold text in bot messages */
    .acbot-bubble strong {
        color: #C084FC;
        font-weight: 600;
    }

    /* --- Quick Action Buttons --- */
    .acbot-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .acbot-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 12px;
        border: 1px solid rgba(142, 68, 173, 0.25);
        background: rgba(142, 68, 173, 0.08);
        color: #C084FC;
        font-size: 0.78rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.25s ease;
        font-family: inherit;
        line-height: 1.3;
    }

    .acbot-action-btn:hover {
        background: rgba(142, 68, 173, 0.2);
        border-color: rgba(142, 68, 173, 0.4);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(106, 13, 173, 0.2);
    }

    .acbot-action-btn:active {
        transform: translateY(0) scale(0.97);
    }

    /* --- Typing Indicator --- */
    .acbot-typing {
        display: none;
        align-self: flex-start;
        gap: 8px;
        max-width: 88%;
        animation: acbotMsgIn 0.3s ease both;
    }

    .acbot-typing.visible {
        display: flex;
    }

    .acbot-typing-avatar {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        background: linear-gradient(135deg, #6A0DAD, #8E44AD);
        color: #fff;
    }

    .acbot-typing-bubble {
        padding: 12px 18px;
        border-radius: 14px;
        border-bottom-left-radius: 4px;
        background: rgba(142, 68, 173, 0.12);
        border: 1px solid rgba(142, 68, 173, 0.12);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .acbot-typing-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #C084FC;
        opacity: 0.4;
        animation: acbotDotBounce 1.4s ease-in-out infinite;
    }

    .acbot-typing-dot:nth-child(2) {
        animation-delay: 0.15s;
    }

    .acbot-typing-dot:nth-child(3) {
        animation-delay: 0.3s;
    }

    @keyframes acbotDotBounce {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
        30%           { transform: translateY(-6px); opacity: 1; }
    }

    /* --- Input Area --- */
    .acbot-input-area {
        flex-shrink: 0;
        padding: 12px 14px;
        border-top: 1px solid rgba(142, 68, 173, 0.12);
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(13, 6, 18, 0.4);
    }

    .acbot-input {
        flex: 1;
        border: 1.5px solid rgba(142, 68, 173, 0.18);
        border-radius: 14px;
        padding: 10px 16px;
        font-size: 0.84rem;
        font-family: inherit;
        background: rgba(142, 68, 173, 0.06);
        color: #FFFFFF;
        outline: none;
        transition: border-color 0.25s ease, background 0.25s ease;
    }

    .acbot-input::placeholder {
        color: rgba(244, 209, 255, 0.3);
    }

    .acbot-input:focus {
        border-color: rgba(142, 68, 173, 0.45);
        background: rgba(142, 68, 173, 0.1);
    }

    .acbot-send {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        border: none;
        background: linear-gradient(135deg, #6A0DAD, #8E44AD);
        color: #fff;
        font-size: 0.95rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.25s ease;
        box-shadow: 0 2px 10px rgba(106, 13, 173, 0.3);
    }

    .acbot-send:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 16px rgba(106, 13, 173, 0.45);
    }

    .acbot-send:active {
        transform: scale(0.95);
    }

    .acbot-send:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    /* --- Responsive --- */
    @media (max-width: 480px) {
        .acbot-window {
            bottom: 0;
            right: 0;
            width: 100%;
            height: 100vh;
            max-height: 100vh;
            border-radius: 0;
        }

        .acbot-trigger {
            bottom: 20px;
            right: 20px;
            width: 56px;
            height: 56px;
            font-size: 1.4rem;
        }

        .acbot-root.open .acbot-window {
            border-radius: 0;
        }
    }

    @media (min-width: 481px) and (max-width: 768px) {
        .acbot-window {
            width: calc(100vw - 40px);
            right: 20px;
            bottom: 90px;
        }
    }
</style>


<!-- ============================================================
     AI CHATBOT — HTML
     ============================================================ -->
<div class="acbot-root" id="acbotRoot">

    <!-- Floating Trigger Button -->
    <button class="acbot-trigger" id="acbotTrigger" aria-label="Open AI Assistant">
        <i class="fa-solid fa-robot acbot-icon-bot"></i>
        <i class="fa-solid fa-xmark acbot-icon-x"></i>
        <span class="acbot-badge" id="acbotBadge">1</span>
    </button>

    <!-- Chat Window -->
    <div class="acbot-window" id="acbotWindow" role="dialog" aria-label="AI Assistant Chat">

        <!-- Header -->
        <div class="acbot-header">
            <div class="acbot-header-avatar">🤖</div>
            <div class="acbot-header-info">
                <div class="acbot-header-name">AI Checker</div>
                <div class="acbot-header-status">
                    <span class="acbot-status-dot"></span>
                    Online
                </div>
            </div>
            <button class="acbot-header-close" id="acbotClose" aria-label="Close chat">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Messages -->
        <div class="acbot-messages" id="acbotMessages">
            <!-- Messages injected by JS -->
        </div>

        <!-- Typing Indicator -->
        <div class="acbot-typing" id="acbotTyping">
            <div class="acbot-typing-avatar">🤖</div>
            <div class="acbot-typing-bubble">
                <span class="acbot-typing-dot"></span>
                <span class="acbot-typing-dot"></span>
                <span class="acbot-typing-dot"></span>
            </div>
        </div>

        <!-- Input Area -->
        <div class="acbot-input-area">
            <input
                type="text"
                class="acbot-input"
                id="acbotInput"
                placeholder="Type your name..."
                autocomplete="off"
            >
            <button class="acbot-send" id="acbotSend" aria-label="Send message">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>


<!-- ============================================================
     AI CHATBOT — JAVASCRIPT
     ============================================================ -->
<script>
(function () {
    'use strict';

    /* ----------------------------------------------------------
       STATE
       ---------------------------------------------------------- */
    var userName      = '';    // Stored user name
    var waitingForName = true; // Are we waiting for the user's name?
    var isOpen         = false;
    var isBusy         = false;
    var welcomed       = false;

    /* ----------------------------------------------------------
       VOICE ENGINE — Web Speech API (MALE voice)
       ---------------------------------------------------------- */

    /** Cached male voice reference */
    var maleVoice = null;

    /** Find and cache a male English voice */
    function findMaleVoice() {
        var voices = window.speechSynthesis.getVoices();
        var maleKeywords = ['male', 'daniel', 'james', 'richard', 'george', 'david', 'mark', 'alex', 'thomas', 'guy', 'aaron', 'fred'];

        // Priority 1: voice with "male" in the name
        for (var i = 0; i < voices.length; i++) {
            var vName = voices[i].name.toLowerCase();
            if (voices[i].lang.startsWith('en') && maleKeywords.indexOf(vName) !== -1) {
                return voices[i];
            }
        }

        // Priority 2: any English voice that is NOT explicitly female
        var femaleKeywords = ['female', 'samantha', 'victoria', 'karen', 'moira', 'tessa', 'fiona', 'zira', 'hazel', 'susan', 'linda', 'catherine', 'google.*female'];
        for (var j = 0; j < voices.length; j++) {
            var vName2 = voices[j].name.toLowerCase();
            var isFemale = false;
            for (var f = 0; f < femaleKeywords.length; f++) {
                if (vName2.indexOf(femaleKeywords[f]) !== -1) {
                    isFemale = true;
                    break;
                }
            }
            if (voices[j].lang.startsWith('en') && !isFemale) {
                return voices[j];
            }
        }

        // Priority 3: any English voice (fallback)
        for (var k = 0; k < voices.length; k++) {
            if (voices[k].lang.startsWith('en')) {
                return voices[k];
            }
        }

        return null;
    }

    /** Speak text with male voice */
    function speak(text) {
        try {
            if (!('speechSynthesis' in window)) return;
            window.speechSynthesis.cancel();

            var utterance = new SpeechSynthesisUtterance(text);
            utterance.rate  = 0.92;
            utterance.pitch = 0.75;   // Low pitch for male sound
            utterance.volume = 1;

            if (maleVoice) {
                utterance.voice = maleVoice;
            }

            window.speechSynthesis.speak(utterance);
        } catch (e) {
            /* Silently fail */
        }
    }

    /* Pre-load voices (some browsers load them async) */
    if ('speechSynthesis' in window) {
        window.speechSynthesis.getVoices();

        if (window.speechSynthesis.onvoiceschanged !== undefined) {
            window.speechSynthesis.onvoiceschanged = function () {
                window.speechSynthesis.getVoices();
                maleVoice = findMaleVoice();
            };
        }

        // Try to find voice immediately (works in some browsers)
        maleVoice = findMaleVoice();
    }

    /* ----------------------------------------------------------
       SOUND ENGINE — Web Audio API (no external files)
       ---------------------------------------------------------- */
    var AudioCtx = window.AudioContext || window.webkitAudioContext;
    var audioCtx = null;

    function playSound(type) {
        try {
            if (!AudioCtx) return;
            if (!audioCtx) audioCtx = new AudioCtx();

            var osc  = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.type = 'sine';

            var freq, vol, dur;
            if (type === 'open') {
                freq = 520; vol = 0.07; dur = 0.25;
            } else if (type === 'send') {
                freq = 700; vol = 0.06; dur = 0.12;
            } else {
                freq = 440; vol = 0.06; dur = 0.2;
            }

            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(vol, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + dur + 0.05);
        } catch (e) {
            /* Silently fail */
        }
    }

    /* ----------------------------------------------------------
       NAME EXTRACTION
       Extracts the actual name from various input patterns:
       - "hana"
       - "my name is hana"
       - "i'm hana"
       - "i am hana"
       - "call me hana"
       - "name's hana"
       - "it's hana"
       ---------------------------------------------------------- */
    function extractName(text) {
        var t = text.trim();

        /* Patterns to strip before getting the name */
        var patterns = [
            /^my\s+name\s+is\s+/i,
            /^my\s+name's\s+/i,
            /^i'?m\s+/i,
            /^i\s+am\s+/i,
            /^call\s+me\s+/i,
            /^name\s+is\s+/i,
            /^name's\s+/i,
            /^it'?s\s+/i,
            /^i'?m\s+/i,
            /^this\s+is\s+/i,
            /^please\s+call\s+me\s+/i,
            /^the\s+name\s+is\s+/i,
            /^myself\s+/i
        ];

        for (var i = 0; i < patterns.length; i++) {
            if (patterns[i].test(t)) {
                var extracted = t.replace(patterns[i], '').trim();
                // Take only the first word as name
                var parts = extracted.split(/\s+/);
                if (parts[0].length > 0) {
                    return parts[0].charAt(0).toUpperCase() + parts[0].slice(1).toLowerCase();
                }
            }
        }

        /* If no pattern matched, treat the whole input as the name
           but only if it looks like a name (no spaces, no special chars, reasonable length) */
        var cleaned = t.replace(/[^a-zA-Z\s'-]/g, '').trim();
        var words = cleaned.split(/\s+/);

        if (words.length === 1 && words[0].length >= 2 && words[0].length <= 20) {
            return words[0].charAt(0).toUpperCase() + words[0].slice(1).toLowerCase();
        }

        /* If multiple words, take the first one that looks like a name */
        for (var j = 0; j < words.length; j++) {
            if (words[j].length >= 2 && words[j].length <= 20 && /^[a-zA-Z'-]+$/.test(words[j])) {
                return words[j].charAt(0).toUpperCase() + words[j].slice(1).toLowerCase();
            }
        }

        return null; // Could not extract a name
    }

    /* ----------------------------------------------------------
       RESPONSE DATABASE
       ---------------------------------------------------------- */
    var responses = {

        upload: function() {
            return 'To upload your assignment:\n\n' +
                '1. Login to your account.\n' +
                '2. Open the Dashboard.\n' +
                '3. Click Upload Assignment.\n' +
                '4. Select ONE file only.\n\n' +
                'Accepted file formats:\n' +
                '&bull; Microsoft Word (.doc)\n' +
                '&bull; Microsoft Word (.docx)\n\n' +
                '5. Click Submit Assignment.\n' +
                '6. Wait while the AI analyzes your file.\n' +
                '7. Once the analysis is complete, you can download your AI analysis report.';
        },

        payment: function() {
            return 'To purchase a subscription:\n\n' +
                '1. Open the Payment page.\n' +
                '2. Choose your preferred subscription plan.\n' +
                '3. Complete the payment using the available payment gateway.\n' +
                '4. Wait for payment confirmation.\n' +
                '5. Your subscription will be activated automatically.';
        },

        score: function() {
            return 'Your AI Score is generated after your assignment has been analyzed.\n\n' +
                'The AI evaluates:\n\n' +
                '&bull; Grammar\n' +
                '&bull; Originality\n' +
                '&bull; Structure\n' +
                '&bull; Formatting\n' +
                '&bull; Readability\n\n' +
                'A higher score indicates better writing quality.';
        },

        account: function() {
            return 'Need help with your account?\n\n' +
                'You can:\n\n' +
                '&bull; Update your profile.\n' +
                '&bull; Change your password.\n' +
                '&bull; View your subscription.\n' +
                '&bull; Contact the administrator if you experience login problems.';
        },

        faq: function() {
            return 'Q: What file formats are accepted?\n' +
                'A: Microsoft Word (.doc), Microsoft Word (.docx)\n\n' +
                'Q: Can I upload multiple files?\n' +
                'A: No. Only ONE assignment file can be uploaded for each submission.\n\n' +
                'Q: Can I download my AI report?\n' +
                'A: Yes. After the AI finishes analyzing your assignment, you can download the AI analysis report.\n\n' +
                'Q: How long does analysis take?\n' +
                'A: Usually within a few moments depending on file size.\n\n' +
                'Q: Can I upload another assignment later?\n' +
                'A: Yes. You may submit another assignment according to your subscription plan.';
        },

        greeting: function() {
            if (userName) {
                return 'Hello ' + userName + '! &#x1F44B;\nHow may I assist you today?';
            }
            return 'Hello! &#x1F44B;\nHow may I assist you today?';
        },

        thanks: function() {
            if (userName) {
                return "You're welcome, " + userName + "!\nI'm always happy to help. &#x1F60A;";
            }
            return "You're welcome!\nI'm always happy to help. &#x1F60A;";
        },

        fallback: function() {
            if (userName) {
                return 'Sorry ' + userName + ', I couldn\'t understand your question.\n\nPlease select one of the quick action buttons or try another keyword.';
            }
            return 'Sorry, I couldn\'t understand your question.\n\nPlease select one of the quick action buttons or try another keyword.';
        }
    };

    /* ----------------------------------------------------------
       WELCOME MESSAGE BUILDER (uses userName if set)
       ---------------------------------------------------------- */
    function buildWelcomeHTML() {
        var greetingLine = userName
            ? '&#x1F44B; <strong>Hai ' + userName + '!</strong>'
            : '&#x1F44B; <strong>Hello!</strong>';

        var introLine = userName
            ? "Welcome to AI Assignment Checker.\nI'm AI Checker, your virtual assistant."
            : "Welcome to AI Assignment Checker.\nI'm your virtual assistant.";

        return greetingLine + '\n\n' + introLine + '\n\n' +
            'I can help you with:\n\n' +
            '&bull; Uploading assignments\n' +
            '&bull; Payment assistance\n' +
            '&bull; AI Score information\n' +
            '&bull; Account support\n' +
            '&bull; Frequently Asked Questions\n\n' +
            'Please choose one of the options below or type your question.\n\n' +
            '<div class="acbot-actions">' +
                '<button class="acbot-action-btn" data-keyword="upload">&#x1F4C4; Upload Assignment</button>' +
                '<button class="acbot-action-btn" data-keyword="payment">&#x1F4B3; Payment Help</button>' +
                '<button class="acbot-action-btn" data-keyword="score">&#x1F4CA; AI Score</button>' +
                '<button class="acbot-action-btn" data-keyword="account">&#x1F464; Account Help</button>' +
                '<button class="acbot-action-btn" data-keyword="faq">&#x2753; FAQ</button>' +
            '</div>';
    }

    /* ----------------------------------------------------------
       ASK FOR NAME MESSAGE
       ---------------------------------------------------------- */
    var askNameHTML = '&#x1F44B; <strong>Hi there!</strong>\n\n' +
        "I'm AI Checker, your virtual assistant.\n\n" +
        "What's your name?";

    /* ----------------------------------------------------------
       KEYWORD MATCHING
       ---------------------------------------------------------- */
    function matchKeyword(text) {
        var t = text.toLowerCase().trim();

        if (/upload|submit|file|document/.test(t)) return 'upload';
        if (/payment|pay|subscribe|plan|price|buy|purchase/.test(t)) return 'payment';
        if (/score|result|grade|analysis|report/.test(t)) return 'score';
        if (/profile|password|account|login|sign/.test(t)) return 'account';
        if (/faq|question|format|multiple|download|how long|later/.test(t)) return 'faq';
        if (/^(hello|hi|hey|good morning|good afternoon|good evening)[\s!?.]*$/i.test(t)) return 'greeting';
        if (/thanks|thank you|thx|ty|appreciate/.test(t)) return 'thanks';

        return 'fallback';
    }

    /* ----------------------------------------------------------
       DOM REFERENCES
       ---------------------------------------------------------- */
    var root     = document.getElementById('acbotRoot');
    var trigger  = document.getElementById('acbotTrigger');
    var closeBtn = document.getElementById('acbotClose');
    var window_  = document.getElementById('acbotWindow');
    var messages = document.getElementById('acbotMessages');
    var typing   = document.getElementById('acbotTyping');
    var input    = document.getElementById('acbotInput');
    var sendBtn  = document.getElementById('acbotSend');
    var badge    = document.getElementById('acbotBadge');

    /* ----------------------------------------------------------
       HELPERS
       ---------------------------------------------------------- */

    function timeNow() {
        var d  = new Date();
        var h  = d.getHours();
        var m  = d.getMinutes();
        var ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
    }

    function scrollBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function addMessage(type, html) {
        var wrapper = document.createElement('div');
        wrapper.className = 'acbot-msg ' + type;

        var avatarIcon = type === 'bot' ? '🤖' : '<i class="fa-solid fa-user" style="font-size:0.75rem"></i>';

        wrapper.innerHTML =
            '<div class="acbot-msg-avatar">' + avatarIcon + '</div>' +
            '<div>' +
                '<div class="acbot-bubble">' + html + '</div>' +
                '<div class="acbot-time">' + timeNow() + '</div>' +
            '</div>';

        messages.appendChild(wrapper);
        scrollBottom();
    }

    function setTyping(show) {
        if (show) {
            typing.classList.add('visible');
            scrollBottom();
        } else {
            typing.classList.remove('visible');
        }
    }

    /** Increment badge if chat is closed */
    function bumpBadge() {
        if (!isOpen) {
            var count = parseInt(badge.textContent) || 0;
            badge.textContent = count + 1;
            badge.classList.remove('hidden');
        }
    }

    /* ----------------------------------------------------------
       BOT REPLY FLOW
       ---------------------------------------------------------- */
    function botReply(key) {
        if (isBusy) return;
        isBusy = true;
        sendBtn.disabled = true;

        setTyping(true);

        setTimeout(function () {
            setTyping(false);

            /* Get response — supports both string and function */
            var resp = typeof responses[key] === 'function'
                ? responses[key]()
                : (responses[key] || responses.fallback());

            addMessage('bot', resp);
            playSound('reply');
            bumpBadge();

            /* After answering, re-show the welcome menu */
            setTimeout(function () {
                setTyping(true);

                setTimeout(function () {
                    setTyping(false);
                    addMessage('bot', buildWelcomeHTML());
                    playSound('reply');
                    bumpBadge();

                    isBusy = false;
                    sendBtn.disabled = false;
                    input.focus();
                }, 700);
            }, 1200);

        }, 800);
    }

    /* ----------------------------------------------------------
       HANDLE NAME INPUT
       Called when waitingForName is true and user sends a message
       ---------------------------------------------------------- */
    function handleNameInput(text) {
        if (isBusy) return;
        isBusy = true;
        sendBtn.disabled = true;

        var name = extractName(text);

        if (name) {
            userName = name;
            waitingForName = false;

            /* Update placeholder to reflect normal mode */
            input.placeholder = 'Type your question...';

            setTyping(true);

            setTimeout(function () {
                setTyping(false);

                /* Personalized greeting */
                var greetingHTML = 'Hai <strong>' + userName + '</strong>! &#x1F60A;\n\n' +
                    "I'm AI Checker. How can I help you today?";

                addMessage('bot', greetingHTML);
                playSound('reply');

                /* Speak the greeting with male voice */
                speak("Hai " + userName + "! I'm AI Checker. How can I help you today?");

                /* Then show the menu */
                setTimeout(function () {
                    setTyping(true);

                    setTimeout(function () {
                        setTyping(false);
                        addMessage('bot', buildWelcomeHTML());
                        playSound('reply');

                        isBusy = false;
                        sendBtn.disabled = false;
                        input.focus();
                    }, 700);
                }, 1200);

            }, 800);

        } else {
            /* Could not extract name — ask again */
            setTyping(true);

            setTimeout(function () {
                setTyping(false);
                addMessage('bot', "I'm sorry, I didn't catch your name. Could you please tell me your name?");
                playSound('reply');

                isBusy = false;
                sendBtn.disabled = false;
                input.focus();
            }, 600);
        }
    }

    /* ----------------------------------------------------------
       SEND USER MESSAGE
       ---------------------------------------------------------- */
    function sendMessage(text) {
        var txt = (text || '').trim();
        if (!txt || isBusy) return;

        addMessage('user', txt);
        playSound('send');
        input.value = '';

        /* If we're waiting for a name, handle it separately */
        if (waitingForName) {
            handleNameInput(txt);
            return;
        }

        var key = matchKeyword(txt);
        botReply(key);
    }

    /* ----------------------------------------------------------
       OPEN / CLOSE
       ---------------------------------------------------------- */
    function openChat() {
        isOpen = true;
        root.classList.add('open');
        badge.classList.add('hidden');
        playSound('open');

        if (!welcomed) {
            welcomed = true;

            if (waitingForName) {
                /* First time: ask for name with voice */
                speak("Hi! I'm AI Checker. What's your name?");

                setTimeout(function () {
                    addMessage('bot', askNameHTML);
                }, 350);
            }
        }

        setTimeout(function () {
            input.focus();
        }, 450);
    }

    function closeChat() {
        isOpen = false;
        root.classList.remove('open');

        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    }

    function toggleChat() {
        if (isOpen) {
            closeChat();
        } else {
            openChat();
        }
    }

    /* ----------------------------------------------------------
       EVENT LISTENERS
       ---------------------------------------------------------- */

    trigger.addEventListener('click', toggleChat);

    closeBtn.addEventListener('click', closeChat);

    sendBtn.addEventListener('click', function () {
        sendMessage(input.value);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(input.value);
        }
    });

    /* Quick action buttons (delegated) */
    messages.addEventListener('click', function (e) {
        var btn = e.target.closest('.acbot-action-btn');
        if (!btn || isBusy) return;

        var keyword = btn.getAttribute('data-keyword');
        var label   = btn.textContent.trim();

        addMessage('user', label);
        playSound('send');

        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';

        botReply(keyword);
    });

    /* Close on Escape */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) {
            closeChat();
        }
    });

})();
</script>