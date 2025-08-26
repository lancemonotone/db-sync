# DB Sync Plugin - SQL Parsing Development Plan

## Overview

Implement BackWPup's actual SQL parsing approach to resolve SQL syntax errors during import operations. This plan is based exclusively on BackWPup's real implementation methods from their codebase.

## Problem Statement

Current SQL parsing fails when encountering HTML content in post_content fields, causing malformed INSERT statements and import failures. The error shows:

```
INSERT INTO `iwg_posts` (...) VALUES ('1', '1', '2025-08-23 20:44:21', '2025-08-23 20:44:21', '<!
INSERT INTO `iwg_post' at line 2
```

## BackWPup Implementation Analysis

Based on research of BackWPup's actual code (`class-mysqldump.php`), their approach uses:

1. **Direct MySQLi connections** for optimal performance
2. **`real_escape_string()`** for proper string escaping
3. **Streaming file I/O** to avoid memory issues
4. **Character-by-character processing** for robust parsing
5. **Proper string literal handling** with escape sequence awareness

## Development Phases

### Phase 1: BackWPup-Style MySQLi Integration

**Status:** 🔴 Not Started  
**Estimated Time:** 1-2 days  
**Priority:** High

#### Tasks:

- [ ] **Task 1.1:** Create `DatabaseSync_SQLParser` class based on BackWPup's approach

  - [ ] Implement MySQLi connection handling (copy BackWPup's `connect()` method)
  - [ ] Add `real_escape_string()` method (copy BackWPup's `escapeString()` method exactly)
  - [ ] Implement file handle management (copy BackWPup's `write()` method)
  - [ ] Add proper error handling and exception management (copy BackWPup's exception classes)

- [ ] **Task 1.2:** Implement BackWPup-style INSERT statement generation

  - [ ] Use BackWPup's `dump_table()` method as exact reference
  - [ ] Implement proper value escaping using `real_escape_string()`
  - [ ] Handle different data types (numeric, string, null, binary) exactly like BackWPup
  - [ ] Use BackWPup's chunking approach (50,000 char limit per INSERT)

- [ ] **Task 1.3:** Create unit tests for MySQLi functionality
  - [ ] Test MySQLi connection handling
  - [ ] Test `real_escape_string()` with various content types
  - [ ] Test file writing operations

#### Success Criteria:

- MySQLi connection works reliably
- String escaping handles HTML content properly
- File operations work without memory issues

---

### Phase 2: BackWPup-Style SQL Import Parsing

**Status:** 🔴 Not Started  
**Estimated Time:** 2-3 days  
**Priority:** High

#### Tasks:

- [ ] **Task 2.1:** Implement character-by-character SQL parsing (BackWPup approach)

  - [ ] Create state machine for parsing (in_string, escaped, paren_depth)
  - [ ] Handle string literals with proper quote detection
  - [ ] Implement escape sequence handling (`\'`, `\"`, `\\`)
  - [ ] Add parentheses balancing for function calls

- [ ] **Task 2.2:** Implement BackWPup-style statement splitting

  - [ ] Split only on semicolons outside string literals
  - [ ] Handle multi-line statements properly
  - [ ] Maintain statement integrity during parsing
  - [ ] Add proper error recovery mechanisms

- [ ] **Task 2.3:** Create comprehensive parsing tests
  - [ ] Test with HTML content in post_content
  - [ ] Test with serialized WordPress data
  - [ ] Test with escaped quotes and special characters
  - [ ] Test with malformed content

#### Success Criteria:

- Successfully parses INSERT statements with HTML content
- Handles all WordPress-specific content types
- Maintains proper statement boundaries

---

### Phase 3: BackWPup-Style Import Execution

**Status:** 🔴 Not Started  
**Estimated Time:** 1-2 days  
**Priority:** High

#### Tasks:

- [ ] **Task 3.1:** Implement BackWPup-style transaction handling

  - [ ] Use START TRANSACTION / COMMIT / ROLLBACK
  - [ ] Add proper error handling and rollback on failure
  - [ ] Implement statement-by-statement execution
  - [ ] Add progress tracking and logging

- [ ] **Task 3.2:** Implement BackWPup-style error handling

  - [ ] Use BackWPup's exception handling approach
  - [ ] Add detailed error logging
  - [ ] Implement graceful failure recovery
  - [ ] Add user-friendly error messages

- [ ] **Task 3.3:** Create import execution tests
  - [ ] Test transaction rollback on errors
  - [ ] Test successful import scenarios
  - [ ] Test error recovery mechanisms
  - [ ] Test performance with large datasets

#### Success Criteria:

- Reliable transaction handling
- Comprehensive error reporting
- Successful import of complex WordPress content

---

### Phase 4: Integration and Optimization

**Status:** 🔴 Not Started  
**Estimated Time:** 1-2 days  
**Priority:** Medium

#### Tasks:

- [ ] **Task 4.1:** Integrate with existing db-sync functionality

  - [ ] Replace current `split_sql()` method with BackWPup-style parser
  - [ ] Update `import_sql()` method to use new approach
  - [ ] Maintain backward compatibility
  - [ ] Add fallback to old method if needed

- [ ] **Task 4.2:** Performance optimization based on BackWPup methods

  - [ ] Implement streaming for large files
  - [ ] Add memory usage monitoring
  - [ ] Optimize MySQLi connection handling
  - [ ] Add progress reporting

- [ ] **Task 4.3:** Final testing and validation
  - [ ] Test with various WordPress installations
  - [ ] Test with different content types
  - [ ] Performance testing with large databases
  - [ ] Edge case testing

#### Success Criteria:

- New parser successfully replaces old method
- No performance regression
- All existing functionality preserved

---

## Implementation Notes

### File Structure

```
wp-content/plugins/db-sync/
├── includes/
│   ├── class-sql-parser.php (NEW - based on BackWPup's class-mysqldump.php)
│   ├── class-import.php (MODIFIED - integrate BackWPup-style parsing)
│   └── class-export.php (MODIFIED - use BackWPup-style generation)
├── tests/
│   └── sql-parser-tests.php (NEW)
└── DEVELOPMENT_PLAN.md (THIS FILE)
```

### Key Classes to Create/Modify

1. **DatabaseSync_SQLParser** (NEW - based on BackWPup's `BackWPup_MySQLDump`)

   - MySQLi connection management
   - `real_escape_string()` implementation
   - Character-by-character parsing
   - File handle management

2. **DatabaseSync_Import** (MODIFIED)

   - Replace `split_sql()` with BackWPup-style parser
   - Update `import_sql()` to use MySQLi approach
   - Add transaction handling

3. **DatabaseSync_Export** (MODIFIED)
   - Use BackWPup-style INSERT generation
   - Implement proper string escaping
   - Add chunking for large datasets

### BackWPup Methods to Implement

1. **Connection Management** (from `BackWPup_MySQLDump::connect()`)
2. **String Escaping** (from `BackWPup_MySQLDump::escapeString()`)
3. **File Writing** (from `BackWPup_MySQLDump::write()`)
4. **INSERT Generation** (from `BackWPup_MySQLDump::dump_table()`)
5. **Error Handling** (from BackWPup's exception classes)

### Testing Strategy

1. **Unit Tests**: Test individual BackWPup-style methods
2. **Integration Tests**: Test with real WordPress SQL files
3. **Performance Tests**: Test with large database exports
4. **Edge Case Tests**: Test with problematic content

### Success Metrics

- [ ] Zero SQL syntax errors during import
- [ ] Successful parsing of HTML content in post_content
- [ ] No performance regression compared to current method
- [ ] Comprehensive error reporting and logging
- [ ] Backward compatibility maintained

## Risk Mitigation

1. **Fallback Strategy**: Keep old parsing method as fallback
2. **Incremental Testing**: Test each phase thoroughly before proceeding
3. **Performance Monitoring**: Monitor memory and execution time
4. **Error Recovery**: Implement graceful error handling

## Timeline

- **Phase 1**: 1-2 days
- **Phase 2**: 2-3 days
- **Phase 3**: 1-2 days
- **Phase 4**: 1-2 days

**Total Estimated Time**: 5-9 days

## Notes

- **Based exclusively on BackWPup's actual implementation** - no WordPress methods
- **Uses MySQLi and `real_escape_string()`** for proper SQL handling
- **Implements character-by-character parsing** for robust statement splitting
- **Follows BackWPup's error handling patterns**
- **Maintains WordPress coding standards**
- **Focuses on solving the specific HTML content parsing issue**
