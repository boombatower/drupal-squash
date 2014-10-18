drupal-squash
=============
Squashed mirror of [drupal/drupal](https://github.com/drupal/drupal) `8.0.x` branch.

Uses [boombatower/git-squash-tags](https://github.com/boombatower/git-squash-tags) to squash all
the commits down to one per tag. A squashed history is handy when deploying to a service like
Acquia which uses a git repository for deployment as it relieves the need to store a bloated
history of Drupal core with no real benefit.

A cron job runs `update.php` every two hours to check for upstream tags and rebuild the squash
branch using travis.

[![Build Status](https://travis-ci.org/boombatower/drupal-squash.svg?branch=8.0.x)]
(https://travis-ci.org/boombatower/drupal-squash)

Usage
-----
Clone the `8.0.x-squash` branch.

```sh
$ git clone --branch 8.0.x-squash https://github.com/boombatower/drupal-squash.git
```
