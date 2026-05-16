---
name: localization-development
description: "Always use localization methods for PHP and Vue when adding strings using the format"
license: MIT
metadata:
  author: kumulicp
---

## PHP Localization

Use the __() method to retrieve strings.

Strings are stored in /resources/lang/{language}

Strings are generally divided based on which controller group they're part of. These include account, admin, auth, profile, and setup.

Any strings that are short and generic, used for things like titles and buttons (among other instances) are put in labels.php

## Vue Localization

Use the $t() method to retrieve strings.

Strings are stored in /resources/js/i18n/locales/{language}.json
