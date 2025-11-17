# Prompt Templates - Feature Documentation

## 🎯 Overview

AgenticGraph now includes a powerful prompt templating system inspired by LangChain, making it easy to create clean, reusable, and maintainable prompts for AI-powered nodes.

**Version**: 1.2.0  
**Status**: ✅ Implemented (Phase 1)

---

## 📦 What's Included

### Classes Created

1. **`PromptTemplate`** - Simple template with variable interpolation
2. **`ChatPromptTemplate`** - Structured chat messages (system/user/assistant)
3. **`Message`** (abstract) - Base class for chat messages
4. **`SystemMessage`** - System-level instructions
5. **`UserMessage`** - User inputs
6. **`AssistantMessage`** - Assistant responses (for few-shot)
7. **`TemplateException`** - Template-specific exceptions

### Integration

- ✅ Integrated with `PrismNode`
- ✅ New methods: `promptTemplate()` and `chatPrompt()`
- ✅ Automatic caching support
- ✅ Token tracking support

---

## 🚀 Quick Start

### Before (Manual Concatenation)

```php
class OldWayNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $prompt = "You are a helpful assistant.\n\n";
        $prompt .= "User question: " . $state->get('question') . "\n\n";
        $prompt .= "Context: " . json_encode($state->get('context')) . "\n\n";
        
        $response = $this->prompt($prompt, []);
        return $state->set('response', $response);
    }
}
```

### After (Template-Based)

```php
class NewWayNode extends PrismNode
{
    protected PromptTemplate $template;
    
    public function __construct()
    {
        $this->template = PromptTemplate::create(
            <<<PROMPT
            You are a helpful assistant.
            
            User question: {question}
            Context: {context}
            PROMPT
        );
    }
    
    public function execute(StateContract $state): StateContract
    {
        $response = $this->promptTemplate($this->template, [
            'question' => $state->get('question'),
            'context' => $state->get('context'),
        ]);
        
        return $state->set('response', $response);
    }
}
```

---

## 📚 Features

### 1. Variable Interpolation

```php
$template = PromptTemplate::create('Hello {name}, you are {age} years old');
$result = $template->format(['name' => 'John', 'age' => 30]);
// Output: "Hello John, you are 30 years old"
```

### 2. Default Values

```php
$template = PromptTemplate::create('Hello {name}!')
    ->withDefaults(['name' => 'Guest']);

$result = $template->format([]); // Uses default
// Output: "Hello Guest!"
```

### 3. Required Variables

```php
$template = PromptTemplate::create('Hello {name}!')
    ->withRequired(['name']);

$template->format([]); // ❌ Throws TemplateException
```

### 4. Automatic Type Formatting

```php
// Arrays → JSON
$template->format(['data' => ['key' => 'value']]);

// Booleans → 'true'/'false'
$template->format(['active' => true]);

// Null → empty string
$template->format(['optional' => null]);
```

### 5. Chat Messages

```php
$template = ChatPromptTemplate::create()
    ->system('You are a helpful assistant')
    ->user('Hello!')
    ->assistant('Hi there!')
    ->user('How are you?');
```

### 6. Few-Shot Learning

```php
$template = ChatPromptTemplate::create()
    ->system('Classify sentiment')
    ->user('I love this!')
    ->assistant('positive')
    ->user('This is terrible')
    ->assistant('negative')
    ->user('Classify: {text}');
```

### 7. Load from Files

```php
$template = PromptTemplate::fromFile(storage_path('prompts/welcome.txt'));
```

### 8. Template Libraries

```php
class PromptLibrary
{
    public static function productRecommendation(): ChatPromptTemplate
    {
        return ChatPromptTemplate::create()
            ->system('You are an expert product assistant')
            ->user('Preferences: {preferences}')
            ->user('Products: {products}');
    }
}
```

---

## 📁 File Structure

```
agenticgraph/src/Prompts/
├── PromptTemplate.php
├── ChatPromptTemplate.php
├── Messages/
│   ├── Message.php
│   ├── SystemMessage.php
│   ├── UserMessage.php
│   └── AssistantMessage.php
└── Exceptions/
    └── TemplateException.php

agenticgraph/examples/
└── PromptTemplatesExample.php (11 examples)

agenticgraph/tests/Unit/Prompts/
├── PromptTemplateTest.php
└── ChatPromptTemplateTest.php
```

---

## 🎓 Examples

### Example 1: E-commerce Support

```php
class ProductSupportNode extends PrismNode
{
    protected ChatPromptTemplate $template;
    
    public function __construct()
    {
        $this->template = ChatPromptTemplate::create()
            ->system('You are a helpful e-commerce assistant')
            ->system('Instructions:
                - Be friendly and helpful
                - Only mention products in the data
                - Highlight key features and prices')
            ->user('Question: {question}')
            ->user('Products: {products}');
    }
    
    public function execute(StateContract $state): StateContract
    {
        $response = $this->chatPrompt($this->template, [
            'question' => $state->get('user_message'),
            'products' => $state->get('products'),
        ]);
        
        return $state->set('response', $response);
    }
}
```

### Example 2: Content Moderation

```php
class ModerationNode extends PrismNode
{
    protected PromptTemplate $template;
    
    public function __construct()
    {
        $this->template = PromptTemplate::create(
            <<<PROMPT
            Analyze this content for safety and appropriateness.
            
            Content: {content}
            
            Provide:
            1. Safety score (0-1)
            2. Issues found (if any)
            3. Recommendation (approve/review/reject)
            PROMPT
        );
    }
    
    public function execute(StateContract $state): StateContract
    {
        $analysis = $this->promptTemplate($this->template, [
            'content' => $state->get('user_content'),
        ]);
        
        return $state->set('moderation_result', $analysis);
    }
}
```

### Example 3: Sentiment Analysis with Few-Shot

```php
class SentimentNode extends PrismNode
{
    protected ChatPromptTemplate $template;
    
    public function __construct()
    {
        $this->template = ChatPromptTemplate::create()
            ->system('Classify sentiment as positive, negative, or neutral')
            ->user('I love this product!')
            ->assistant('positive')
            ->user('This is terrible quality')
            ->assistant('negative')
            ->user('It arrived on time')
            ->assistant('neutral')
            ->user('Classify: {text}');
    }
    
    public function execute(StateContract $state): StateContract
    {
        $sentiment = $this->chatPrompt($this->template, [
            'text' => $state->get('review_text'),
        ]);
        
        return $state->set('sentiment', trim($sentiment));
    }
}
```

---

## ✅ Benefits

### 1. Cleaner Code
- No more string concatenation
- Better readability
- Easier to maintain

### 2. Reusability
- Define once, use everywhere
- Create template libraries
- Share across projects

### 3. Type Safety
- Automatic type formatting
- Variable validation
- IDE autocomplete support

### 4. Testability
- Easy to unit test
- Mock-friendly
- Predictable behavior

### 5. Maintainability
- Separate prompts from logic
- Load from files
- Version control friendly

### 6. Professional API
- LangChain-inspired
- Industry standard patterns
- Familiar to AI developers

---

## 🧪 Testing

### Unit Tests

```php
test('template formats correctly', function () {
    $template = PromptTemplate::create('Hello {name}!');
    $result = $template->format(['name' => 'World']);
    
    expect($result)->toBe('Hello World!');
});

test('chat template with few-shot', function () {
    $template = ChatPromptTemplate::create()
        ->system('Classify sentiment')
        ->user('I love this!')
        ->assistant('positive')
        ->user('Classify: {text}');
    
    $formatted = $template->format(['text' => 'Amazing!']);
    
    expect($formatted)->toHaveCount(4);
});
```

### Integration Tests

```php
test('prism node uses template', function () {
    $node = new MyTemplateNode();
    $state = new State(['question' => 'What is Laravel?']);
    
    $result = $node->execute($state);
    
    expect($result->has('response'))->toBeTrue();
});
```

---

## 📖 Documentation

- **Main Documentation**: Added to `documentation.md` (Prompt Templates section)
- **Examples**: `examples/PromptTemplatesExample.php` (11 comprehensive examples)
- **Tests**: Full test coverage in `tests/Unit/Prompts/`
- **This File**: Feature overview and quick reference

---

## 🔮 Future Enhancements (Phase 2 & 3)

### Phase 2: Advanced Features
- Partial templates (includes)
- Conditional sections
- Template inheritance
- Better Prism message array integration

### Phase 3: Enterprise Features
- Template versioning
- A/B testing support
- Analytics integration
- Template marketplace

---

## 🎯 Migration Guide

### For Existing Code

**Step 1**: Identify manual prompt building
```php
// Find code like this
$prompt = "System: " . $instructions . "\n";
$prompt .= "User: " . $question . "\n";
```

**Step 2**: Convert to template
```php
$template = ChatPromptTemplate::create()
    ->system('{instructions}')
    ->user('{question}');
```

**Step 3**: Use in node
```php
$response = $this->chatPrompt($template, [
    'instructions' => $instructions,
    'question' => $question,
]);
```

### No Breaking Changes

- ✅ Existing `prompt()` method still works
- ✅ New methods are additions, not replacements
- ✅ Fully backward compatible
- ✅ Opt-in feature

---

## 💡 Best Practices

1. **Define templates in constructor** - Reuse instances
2. **Use heredoc for multiline** - Better readability
3. **Create template libraries** - Share across nodes
4. **Use required variables** - Fail fast
5. **Load from files** - Separate concerns
6. **Use chat templates** - Better structure
7. **Add few-shot examples** - Improve accuracy
8. **Test templates** - Unit test formatting
9. **Document variables** - Help other developers
10. **Version templates** - Track changes

---

## 🤝 Contributing

To add new template features:

1. Add to `src/Prompts/`
2. Write tests in `tests/Unit/Prompts/`
3. Add examples to `examples/PromptTemplatesExample.php`
4. Update `documentation.md`
5. Submit PR

---

## 📞 Support

- **Documentation**: See `documentation.md` → Prompt Templates section
- **Examples**: See `examples/PromptTemplatesExample.php`
- **Tests**: See `tests/Unit/Prompts/`
- **Issues**: GitHub Issues

---

**Created**: November 17, 2025  
**Version**: 1.2.0  
**Status**: ✅ Production Ready  
**Phase**: 1 of 3 Complete

The prompt templating system is now ready for production use! 🎉
