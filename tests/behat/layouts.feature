@local @local_awareness
Feature: A notice arrives in the layout, the position and the entrance its author chose
  In order to give an announcement the shape it needs
  As a site administrator
  I need a notice to render as the layout I picked, and to preview it as the reader will get it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | bilbo    | Bilbo     | Baggins  | bilbo@westfarthing.invalid |
    And I change window size to "large"
    And I log in as "admin"
    And I navigate to "Awareness > Settings" in site administration
    And I click on "Enabled" "checkbox"
    And I click on "Save changes" "button"
    And I log out

  @javascript
  Scenario: A card notice arrives compact, in the corner it was given
    Given the following site notices exist
      | title   | content                   | template | position | animation |
      | App tip | get the app for reminders | card     | top-end  | fade      |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "get the app for reminders"
    And ".awareness.la-tpl-card.la-pos-top-end" "css_element" should exist
    # A card is the one layout narrower than core's large dialogue, and the template bakes that class in.
    And ".awareness.modal-lg" "css_element" should not exist
    And I click on "awareness-closebtn-footer" "button"
    And I should see "You are logged in as Bilbo Baggins"

  @javascript
  Scenario: A video notice shows a player the site's own filter built, and video.js took it over
    Given the following site notices exist
      | title | content           | template | videourl                     |
      | Tour  | watch the library | video    | https://example.com/tour.mp4 |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "watch the library"
    # The markup is the filter's; the vjs-paused class is stamped by video.js when it initialises,
    # which proves the loader already on the page picked up a player the page did not render.
    And ".awareness.la-tpl-video .mediaplugin_videojs" "css_element" should exist
    And ".awareness.la-tpl-video .video-js.vjs-paused" "css_element" should exist

  @javascript
  Scenario: A carousel turns its slides one at a time
    Given the following site notices exist
      | title | content           | template |
      | News  | the semester news | carousel |
    And the following site notice slides exist
      | notice | sortorder | caption                | image   |
      | News   | 0         | the new computer lab   | lab.png |
      | News   | 1         | the library opens late |         |
    When I log in as "bilbo"
    And I am on site homepage
    Then I should see "the new computer lab"
    And I should not see "the library opens late"
    When I click on "awareness-carousel-next" "button"
    Then I should see "the library opens late"
    And I should not see "the new computer lab"

  @javascript
  Scenario: The editor previews the layout the author picked, in the real dialogue
    Given I log in as "admin"
    And I navigate to "Awareness > Manage" in site administration
    And I click on "Create new notice" "link"
    And I set the field "Title" to "Scheduled maintenance"
    And I set the field "Content" to "The library closes at six."
    And I expand all fieldsets
    # The radio itself is clipped off-screen behind its card-shaped label; the label is what a person clicks.
    And I click on "Card" "text"
    And I click on "Preview" "button"
    Then I should see "The library closes at six." in the "Scheduled maintenance" "dialogue"
    And ".awareness.la-tpl-card" "css_element" should exist
    When I press the escape key
    Then I should not see "The library closes at six."
