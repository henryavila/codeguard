# CodeGuard AI Analysis Rules — Universal

## How to Apply Universal Patterns

These patterns apply to ANY codebase regardless of language or framework.

### Analysis Priority
1. Critical patterns first (architecture violations, god objects)
2. Warning patterns second (DRY, function size)
3. Suggestions last (parameter count, constructor params)

### False Positive Prevention
- DRY: Visual similarity is NOT duplication. Only flag LOGIC duplication (same business behavior in two places)
- Small functions: Getter/setter methods don't count toward complexity
- Few arguments: Constructor dependency injection parameters are acceptable (they represent composition, not data)
- No god object: Framework base classes (Controller, Model) naturally have many methods — only flag user-defined classes
- Bounded contexts: Shared kernel types (IDs, value objects) crossing module boundaries is acceptable

### Severity Classification
- **Critical**: Core architecture broken — the violation undermines the system's structural integrity
- **Warning**: Pattern partially followed — deviation is significant but not system-breaking
- **Suggestion**: Improvement opportunity — code works but could be cleaner
