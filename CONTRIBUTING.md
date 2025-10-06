# Contributing to VectorLiteAdmin

Thank you for your interest in contributing to VectorLiteAdmin! We welcome contributions from the community to help improve and expand this vector database management platform.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [How to Contribute](#how-to-contribute)
- [Development Guidelines](#development-guidelines)
- [Testing](#testing)
- [Submitting Changes](#submitting-changes)
- [Reporting Issues](#reporting-issues)

## Code of Conduct

This project follows a code of conduct to ensure a welcoming environment for all contributors. By participating, you agree to:

- Be respectful and inclusive
- Focus on constructive feedback
- Accept responsibility for mistakes
- Show empathy towards other contributors
- Help create a positive community

## Getting Started

### Prerequisites

Before you begin, ensure you have:

- **PHP 7.4+** with SQLite3 extension
- **Git** for version control
- **Web server** (Apache/Nginx) or PHP's built-in server
- **cURL extension** for API calls
- **Composer** (optional, for dependency management)

### Fork and Clone

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/yourusername/vectorliteadmin.git
   cd vectorliteadmin
   ```
3. Set up the upstream remote:
   ```bash
   git remote add upstream https://github.com/original-repo/vectorliteadmin.git
   ```

## Development Setup

### Local Development Environment

1. **Start the development server:**
   ```bash
   php -S localhost:8000 -t .
   ```

2. **Access the application:**
   Open `http://localhost:8000` in your browser

3. **Initial configuration:**
   - Copy `vectorliteadmin.config.sample.php` to `vectorliteadmin.config.php`
   - Configure your settings and API keys

### Testing with Different Providers

Test with various embedding providers:

- **OpenAI**: Requires API key
- **Ollama**: Local setup with `ollama pull nomic-embed-text`
- **LM Studio**: Local setup with embedding models
- **Gemini**: Google AI API key

## How to Contribute

### Types of Contributions

We welcome various types of contributions:

- **Bug fixes** - Fix issues and improve stability
- **New features** - Add functionality to the platform
- **Documentation** - Improve docs, tutorials, examples
- **Tests** - Add or improve test coverage
- **UI/UX improvements** - Enhance user interface and experience
- **Performance optimizations** - Improve speed and efficiency
- **Security enhancements** - Address security vulnerabilities

### Finding Issues to Work On

- Check the [Issues](https://github.com/yourusername/vectorliteadmin/issues) page
- Look for issues labeled `good first issue` or `help wanted`
- Check the project's roadmap and TODO items
- Consider improving documentation or adding tests

## Development Guidelines

### Code Style

- Follow **PHP-FIG PSR standards** (PSR-1, PSR-12)
- Use **camelCase** for variables and functions
- Use **PascalCase** for classes
- Use **UPPER_CASE** for constants
- Maintain consistent indentation (4 spaces)
- Add meaningful comments for complex logic

### PHP Best Practices

```php
// Good: Clear variable names and comments
function processDocumentChunks(array $documentChunks): bool {
    // Validate input
    if (empty($documentChunks)) {
        return false;
    }

    // Process each chunk
    foreach ($documentChunks as $chunk) {
        // Implementation here
    }

    return true;
}

// Avoid: Unclear names and lack of comments
function proc($arr) {
    if (!$arr) return false;
    foreach ($arr as $c) {
        // what does this do?
    }
    return true;
}
```

### Security Considerations

- **Never** commit API keys or sensitive credentials
- **Always** use prepared statements for database queries
- **Always** validate and sanitize user input
- **Always** escape output to prevent XSS
- Follow the principle of **least privilege**

### Database Schema Changes

When modifying the database schema:

1. Document the changes in the commit message
2. Update any relevant documentation
3. Consider backward compatibility
4. Test migrations thoroughly

### Commit Guidelines

- Use clear, descriptive commit messages
- Start with a verb (Add, Fix, Update, Remove, etc.)
- Keep commits focused on a single change
- Reference issue numbers when applicable

Examples:
```
Fix: Resolve embedding queue processing bug (#123)
Add: Support for PDF text extraction
Update: Improve error messages for API failures
```

## Testing

### Testing Strategy

We aim for comprehensive testing coverage:

- **Unit Tests**: Test individual components and functions
- **Integration Tests**: Test component interactions
- **API Tests**: Test embedding provider integrations
- **UI Tests**: Test user interface functionality

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit tests/DatabaseTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
```

### Writing Tests

```php
class DocumentManagerTest extends TestCase {
    public function testCreateDocument() {
        // Arrange
        $manager = new DocumentManager();
        $data = [
            'title' => 'Test Document',
            'content' => 'Test content'
        ];

        // Act
        $result = $manager->createDocument($data);

        // Assert
        $this->assertTrue($result);
        $this->assertFileExists('/path/to/document');
    }
}
```

## Submitting Changes

### Pull Request Process

1. **Create a feature branch:**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes:**
   - Write clear, focused commits
   - Test your changes thoroughly
   - Update documentation if needed

3. **Push your branch:**
   ```bash
   git push origin feature/your-feature-name
   ```

4. **Create a Pull Request:**
   - Use a clear title describing the change
   - Provide a detailed description
   - Reference any related issues
   - Include screenshots for UI changes

### Pull Request Template

```markdown
## Description
Brief description of the changes made.

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Documentation update
- [ ] Performance improvement
- [ ] Security enhancement

## Testing
- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] Manual testing completed
- [ ] Documentation updated

## Screenshots (if applicable)
Add screenshots to show UI changes.

## Checklist
- [ ] Code follows project style guidelines
- [ ] All tests pass
- [ ] Documentation updated
- [ ] Security considerations addressed
- [ ] Backward compatibility maintained
```

## Reporting Issues

### Bug Reports

When reporting bugs, please include:

- **Clear title** describing the issue
- **Steps to reproduce** the problem
- **Expected behavior** vs. actual behavior
- **Environment details** (PHP version, OS, etc.)
- **Error messages** or screenshots
- **Browser console logs** if applicable

### Feature Requests

For new features, please provide:

- **Clear description** of the proposed feature
- **Use case** or problem it solves
- **Proposed implementation** if you have ideas
- **Mockups or examples** if applicable

## Recognition

Contributors will be recognized in:

- The project's CONTRIBUTORS file
- Release notes
- GitHub's contributor insights

Thank you for contributing to VectorLiteAdmin! 🚀
