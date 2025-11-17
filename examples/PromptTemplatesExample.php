<?php

/**
 * AgenticGraph Prompt Templates Examples
 *
 * This file demonstrates how to use the new prompt templating system
 * to create clean, reusable, and maintainable prompts.
 */

namespace Examples;

use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;
use Shahzad\AgenticGraph\Prompts\Messages\SystemMessage;
use Shahzad\AgenticGraph\Prompts\Messages\UserMessage;

// ============================================================================
// Example 1: Simple Prompt Template
// ============================================================================

class SimpleTemplateExample extends PrismNode
{
    protected PromptTemplate $template;

    public function __construct()
    {
        // Define template once in constructor
        $this->template = PromptTemplate::create(
            'You are a {role}. Answer this question: {question}'
        );
    }

    public function execute(StateContract $state): StateContract
    {
        // Use template with variables
        $response = $this->promptTemplate($this->template, [
            'role' => 'helpful assistant',
            'question' => $state->get('user_question'),
        ]);

        return $state->set('response', $response);
    }
}

// ============================================================================
// Example 2: Multi-line Template with Heredoc
// ============================================================================

class EcommerceProductNode extends PrismNode
{
    protected PromptTemplate $template;

    public function __construct()
    {
        $this->template = PromptTemplate::create(
            <<<PROMPT
            You are a helpful e-commerce customer support assistant.

            User question: {question}

            Product data (JSON):
            {products}

            Instructions:
            - Present products in a friendly, helpful way
            - Highlight key features, ratings, and prices
            - Be enthusiastic but honest
            - Keep it concise but informative
            - ONLY mention products in the data - do NOT hallucinate

            Response:
            PROMPT
        );
    }

    public function execute(StateContract $state): StateContract
    {
        $response = $this->promptTemplate($this->template, [
            'question' => $state->get('user_message'),
            'products' => $state->get('products'), // Arrays are auto-formatted as JSON
        ]);

        return $state->set('formatted_response', $response);
    }
}

// ============================================================================
// Example 3: Chat Prompt Template (LangChain-style)
// ============================================================================

class ChatStyleNode extends PrismNode
{
    protected ChatPromptTemplate $template;

    public function __construct()
    {
        $this->template = ChatPromptTemplate::create()
            ->system('You are an expert product recommendation assistant')
            ->system('Always base recommendations on actual product data')
            ->system('Be enthusiastic but honest')
            ->user('User preferences: {preferences}')
            ->user('Available products: {products}')
            ->user('Recommend the best options and explain why');
    }

    public function execute(StateContract $state): StateContract
    {
        $response = $this->chatPrompt($this->template, [
            'preferences' => $state->get('user_preferences'),
            'products' => $state->get('available_products'),
        ]);

        return $state->set('recommendations', $response);
    }
}

// ============================================================================
// Example 4: Few-Shot Learning with Assistant Messages
// ============================================================================

class FewShotExampleNode extends PrismNode
{
    protected ChatPromptTemplate $template;

    public function __construct()
    {
        $this->template = ChatPromptTemplate::create()
            ->system('You are a sentiment analyzer. Classify text as positive, negative, or neutral.')

            // Few-shot examples
            ->user('I love this product!')
            ->assistant('positive')

            ->user('This is terrible quality.')
            ->assistant('negative')

            ->user('The product arrived on time.')
            ->assistant('neutral')

            // Actual query
            ->user('Text to analyze: {text}');
    }

    public function execute(StateContract $state): StateContract
    {
        $sentiment = $this->chatPrompt($this->template, [
            'text' => $state->get('review_text'),
        ]);

        return $state->set('sentiment', trim($sentiment));
    }
}

// ============================================================================
// Example 5: Reusable Template Library
// ============================================================================

class PromptLibrary
{
    /**
     * Product recommendation template
     */
    public static function productRecommendation(): ChatPromptTemplate
    {
        return ChatPromptTemplate::create()
            ->system('You are an expert product recommendation assistant')
            ->system('Always base recommendations on actual product data')
            ->user('User preferences: {preferences}')
            ->user('Available products: {products}')
            ->user('Budget: {budget}')
            ->user('Recommend the best options and explain why');
    }

    /**
     * Order status template
     */
    public static function orderStatus(): PromptTemplate
    {
        return PromptTemplate::create(
            <<<PROMPT
            You are a customer service assistant helping with order status.

            Order details: {order}
            User question: {question}

            Provide a clear, friendly update on the order status.
            Include tracking information if available.
            PROMPT
        );
    }

    /**
     * Content moderation template
     */
    public static function contentModeration(): ChatPromptTemplate
    {
        return ChatPromptTemplate::create()
            ->system('You are a content moderation assistant')
            ->system('Analyze content for safety, appropriateness, and policy compliance')
            ->user('Content to moderate: {content}')
            ->user('Provide a safety score (0-1) and explanation');
    }

    /**
     * Email classification template
     */
    public static function emailClassification(): PromptTemplate
    {
        return PromptTemplate::create(
            <<<PROMPT
            Classify this email into one of these categories:
            - technical: Technical issues, bugs, errors
            - billing: Payment, refunds, subscriptions
            - account: Login, password, profile
            - general: Other inquiries

            Email: {email}

            Category:
            PROMPT
        );
    }
}

// Usage of library
class RecommendationNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $template = PromptLibrary::productRecommendation();

        $response = $this->chatPrompt($template, [
            'preferences' => $state->get('user_preferences'),
            'products' => $state->get('products'),
            'budget' => $state->get('budget'),
        ]);

        return $state->set('recommendations', $response);
    }
}

// ============================================================================
// Example 6: Template with Default Values
// ============================================================================

class TemplateWithDefaultsNode extends PrismNode
{
    protected PromptTemplate $template;

    public function __construct()
    {
        $this->template = PromptTemplate::create(
            'You are a {role}. {instructions} Question: {question}'
        )->withDefaults([
            'role' => 'helpful assistant',
            'instructions' => 'Provide a clear and concise answer.',
        ]);
    }

    public function execute(StateContract $state): StateContract
    {
        // Only need to provide 'question', others use defaults
        $response = $this->promptTemplate($this->template, [
            'question' => $state->get('user_question'),
            // Can override defaults if needed
            // 'role' => 'expert advisor',
        ]);

        return $state->set('response', $response);
    }
}

// ============================================================================
// Example 7: Template with Required Variables
// ============================================================================

class TemplateWithValidationNode extends PrismNode
{
    protected PromptTemplate $template;

    public function __construct()
    {
        $this->template = PromptTemplate::create(
            'Analyze this {item_type}: {item_content}'
        )->withRequired(['item_type', 'item_content']);
    }

    public function execute(StateContract $state): StateContract
    {
        // Will throw TemplateException if required variables are missing
        $response = $this->promptTemplate($this->template, [
            'item_type' => $state->get('type'),
            'item_content' => $state->get('content'),
        ]);

        return $state->set('analysis', $response);
    }
}

// ============================================================================
// Example 8: Loading Templates from Files
// ============================================================================

class FileBasedTemplateNode extends PrismNode
{
    protected PromptTemplate $template;

    public function __construct()
    {
        // Load template from file
        // File: storage/prompts/product_description.txt
        $this->template = PromptTemplate::fromFile(
            storage_path('prompts/product_description.txt')
        );
    }

    public function execute(StateContract $state): StateContract
    {
        $response = $this->promptTemplate($this->template, [
            'product_name' => $state->get('product.name'),
            'features' => $state->get('product.features'),
            'price' => $state->get('product.price'),
        ]);

        return $state->set('description', $response);
    }
}

// ============================================================================
// Example 9: Dynamic Template Building
// ============================================================================

class DynamicTemplateNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $template = ChatPromptTemplate::create()
            ->system('You are a helpful assistant');

        // Add context based on state
        if ($state->has('user_history')) {
            $template->system('User history: {history}');
        }

        if ($state->get('is_premium_user')) {
            $template->system('This is a premium user - provide detailed responses');
        }

        $template->user('Question: {question}');

        $response = $this->chatPrompt($template, [
            'history' => $state->get('user_history', ''),
            'question' => $state->get('question'),
        ]);

        return $state->set('response', $response);
    }
}

// ============================================================================
// Example 10: Inspecting Template Variables
// ============================================================================

class TemplateInspectionExample
{
    public function demonstrateInspection(): void
    {
        $template = PromptTemplate::create(
            'Hello {name}, you have {count} messages about {topic}'
        );

        // Get all variables used in template
        $variables = $template->getVariables();
        // Returns: ['name', 'count', 'topic']

        echo "Template requires these variables: " . implode(', ', $variables);
    }
}

// ============================================================================
// Example 11: Before and After Comparison
// ============================================================================

// BEFORE: Manual string concatenation
class OldWayNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $prompt = "You are a helpful e-commerce customer support assistant.\n\n";
        $prompt .= "User question: " . $state->get('user_message') . "\n\n";
        $prompt .= "Product data (JSON):\n" . json_encode($state->get('products')) . "\n\n";
        $prompt .= "Instructions:\n";
        $prompt .= "- Present products in a friendly, helpful way\n";
        $prompt .= "- Highlight key features, ratings, and prices\n";
        $prompt .= "- Be enthusiastic but honest\n";

        $response = $this->prompt($prompt, []);
        return $state->set('response', $response);
    }
}

// AFTER: Clean template-based approach
class NewWayNode extends PrismNode
{
    protected PromptTemplate $template;

    public function __construct()
    {
        $this->template = PromptTemplate::create(
            <<<PROMPT
            You are a helpful e-commerce customer support assistant.

            User question: {question}

            Product data (JSON):
            {products}

            Instructions:
            - Present products in a friendly, helpful way
            - Highlight key features, ratings, and prices
            - Be enthusiastic but honest
            PROMPT
        );
    }

    public function execute(StateContract $state): StateContract
    {
        $response = $this->promptTemplate($this->template, [
            'question' => $state->get('user_message'),
            'products' => $state->get('products'),
        ]);

        return $state->set('response', $response);
    }
}
