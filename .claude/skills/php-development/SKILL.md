---
name: php-development
description: "Use the following rules for writing code"
license: MIT
metadata:
  author: kumulicp
---

## Code standards

When writing variables, they should be written in snake case such as `$this_is_a_variable = ''`

When writing the arrays, keys should be written in snake case such as `$array = ['this_array_key' => 'value']`;

## Debugging

When debugging, use `dump()` to output the value of a variable or `dd()` to output the value and die.

Do not remove `dd()` calls unless explicitly asked to. When the user is debugging a problem, `dd()` calls are intentional diagnostic tools — removing them silently destroys their debugging session mid-investigation. Only remove `dd()` when the user explicitly asks to clean up or finalize debug code.
