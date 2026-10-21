<?php

declare(strict_types=1);

namespace FinePrint\Security;

/**
 * Text addressed to an AI rather than to a human reader.
 *
 * Matched against NORMALISED text: invisible characters removed, every run of
 * whitespace one space, ASCII lower-cased. So `IGNORE  ALL\nPREVIOUS PROMPTS`
 * and `ign\u{200B}ore previous prompts` read the same as the plain phrase.
 *
 * This is a DETECTOR, not a proof. It catches the phrasings people actually
 * use; a determined author can phrase around any list. Each pattern is written
 * to need the AI-directed shape — a verb AND its object, or an AI-specific
 * noun — so ordinary prose that merely shares a word ("the previous section's
 * instructions", "our system prompts users") does not trip it.
 */
final class InstructionPatterns
{
    /** @var array<string, array{0: string, 1: string}> id => [severity, regex] */
    public const PATTERNS = [
        'ignore-previous' => ['high', '/\b(?:ignore|disregard|forget|override|skip)\s(?:(?:all|any|the|your|every|of|these|those)\s)*(?:previous|prior|above|earlier|preceding|foregoing|former|original|existing|system)\s(?:instructions?|prompts?|messages?|directions?|context|rules|guidelines|commands?|programming|guidance)\b/u'],
        'ignore-above' => ['medium', '/\b(?:ignore|disregard)\s(?:all\s|everything\s)?(?:the\s)?above\b/u'],
        'new-instructions' => ['high', '/\b(?:new|updated|revised|real|actual|true|hidden|secret|additional|override)\s(?:system\s)?instructions?\s?:/u'],
        'addressed-to-ai' => ['high', '/\b(?:note|message|instructions?|attention|memo|reminder|directive|hey|dear)\s?(?:to|for)?\s?(?:the\s|all\s|any\s)?(?:ai|a\.i\.|llms?|large\slanguage\smodels?|language\smodels?|chatbots?|chatgpt|gpt(?:-\d+)?|claude|gemini|copilot)(?:\s(?:agents?|assistants?|models?|systems?|tools?|readers?))?\s?[:,]/u'],
        'do-not-tell' => ['high', '/\b(?:do\snot|don\'t|dont|never|must\snot)\s(?:tell|inform|mention|reveal|disclose|alert|notify|show)\s(?:this\s|it\s)?(?:to\s)?(?:the\s)?(?:user|human|reader|operator|recipient|customer)s?\b/u'],
        'reveal-prompt' => ['high', '/\b(?:reveal|print|output|repeat|show|display|leak|dump)\s(?:me\s)?(?:your|the)\s(?:full\s|entire\s|original\s|hidden\s|initial\s)?(?:system\s(?:prompt|message|instructions?)|initial\sprompt|hidden\sprompt|instructions)\b/u'],
        'role-reassignment' => ['medium', '/\byou\sare\snow\s(?:a\s|an\s|the\s|in\s)?(?:unrestricted\s|jailbroken\s|unfiltered\s|new\s|different\s|evil\s)?(?:ai|a\.i\.|assistant|language\smodel|llm|chatbot|dan|developer\smode|god\smode|admin\smode|jailbreak\smode|unrestricted|jailbroken)\b/u'],
        'chat-template' => ['high', '/<\|(?:im_start|im_end|system|user|assistant|endoftext|eot_id|start_header_id|end_header_id)\|>|\[\/?inst\]|<<\/?sys>>|<\/?(?:system|instructions?)>/u'],
        'exfiltrate' => ['high', '/\bexfiltrat(?:e|es|ed|ing|ion)\b|\b(?:send|post|upload|forward|email|transmit|leak)\s(?:(?:all|the|this|your|any)\s)*(?:conversation|chat\shistory|context\swindow|system\sprompt|credentials|secrets|api\skeys?|access\stokens?|passwords?)\s(?:to|at)\b/u'],
        'jailbreak' => ['medium', '/\b(?:jailbreak|jailbroken|do\sanything\snow|dan\smode|developer\smode\senabled)\b/u'],
        'bypass-safety' => ['high', '/\b(?:override|bypass|disable|ignore)\s(?:all\s)?your\s(?:safety|security|content|ethical)\s(?:guidelines|restrictions|filters?|policies|rules|training|programming)\b/u'],
        'role-prefix' => ['low', '/(?:^|\s)(?:system|assistant|developer)\s?:\s/u'],
    ];
}
