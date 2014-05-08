AttentiveBot
============

A Twitter bot that finds easily overlooked tweets

Dependencies
------------
* PHP 5.x
* cURL extension

Installation
------------
1. Rename `config-example.php` as `config.php` and open it in your text editor
2. Change the `SCREEN_NAME` to something of your own choosing
3. Using that screen name, sign into https://dev.twitter.com/ (i.e., don't use your personal account)
4. Click on your user icon, choose [My Applications](https://apps.twitter.com/)
5. Click [Create New App](https://apps.twitter.com/app/new)
6. Click on your new application
7. Look for "Access level" and click "modify app permissions"
8. Choose "Read, write, and direct messages" (you may be asked to enter and verify a phone number)
9. Click the "API Keys" tab, scroll down and click "Generate access token"
10. Wait a moment, reload the page, then copy and paste the following values into your `config.php`:  
    * API Key
    * API Secret
    * Access token
    * Access token secret
11. Make sure `data` folder is writable by the web user
12. Load up `index.php` in a web browser
