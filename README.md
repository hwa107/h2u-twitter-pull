Twitter Pull
============

Description
-----------

This plugin will check Twitter every 10 minutes for any new tweets, if there's then the plugin will pull them all and post them as normal WordPress blog post. Several options available such as define the category for the post as well as not to post twitter mentions.

FAQ
---

1.  Q: After I installed the plugin, it's not pulling all my old tweets but only one latest tweet, is this an intended behavior?
    A: Yes it is. Please notes that during the first pull, the plugin will only pull the last single tweet, then the plugin will pull all the new tweets after that for every 10 minutes.

2.  Q: I know I tweeted a lot for the last 10 minutes, but why Twitter Pull only show the latest 20 tweets?
    A: This is the limitation of Twitter API, it only allow maximum number of 20 latest tweets per pull.

Changelog
---------

v1.0.0

- Initial release

v1.0.1

- Added thumbnail creation to downloaded image
- Now you can add hashtags as post tags

v1.0.2

- Fix an issue where new post will be created even with empty tweet

v1.1.0

- Using Twitter API 1.1

v1.1.1

- Some minor typo correction
