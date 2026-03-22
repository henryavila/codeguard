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
- Few arguments: Constructor dependency injection parameters are acceptable (they represent composition, not data). The threshold of 7 comes from SonarQube S107 and Miller's Law; 4+ is a review signal, not a finding
- No god object: Framework base classes (Controller, Model) naturally have many methods — only flag user-defined classes. Thresholds: 10 public methods (PHPMD) as review signal, 20 (SonarQube) as finding; 15 fields (PHPMD) as review signal, 20 (SonarQube) as finding
- Bounded contexts: Shared kernel types (IDs, value objects) crossing module boundaries is acceptable
- Single responsibility: Framework lifecycle methods (setUp, tearDown, boot) are not multiple responsibilities — they are part of a single framework contract
- Separation of concerns: Thin orchestration in controllers (calling a service method + returning a response) is not a violation — controllers are meant to coordinate
- Layer dependency direction: Events and listeners crossing layers is acceptable — events are contracts, not dependencies
- No circular dependencies: Shared value objects or DTOs used by multiple modules are not circular dependencies — they belong to a shared kernel
- No deep inheritance: Framework-mandated inheritance (extending Controller, Model, TestCase) does not count toward inheritance depth. Threshold of 6 comes from PHPMD, NDepend, and Microsoft CA1501
- No constructor many params: DI container-injected dependencies are acceptable; only flag manual construction with many arguments. Same threshold as functions (7, per SonarQube S107). A class needing 5+ injected dependencies is a SRP review signal
- No long switch: Configuration-driven switches (e.g., factory methods mapping type to class) are acceptable patterns — they are data, not branching logic. Switches with logic per case should be reviewed when cyclomatic complexity exceeds 10 (McCabe/PHPMD)
- Consistent error handling: Logging-only catch blocks (log + rethrow) are valid error handling; only flag truly empty catches that silently swallow exceptions

### Severity Classification
- **Critical**: Core architecture broken — the violation undermines the system's structural integrity
- **Warning**: Pattern partially followed — deviation is significant but not system-breaking
- **Suggestion**: Improvement opportunity — code works but could be cleaner
