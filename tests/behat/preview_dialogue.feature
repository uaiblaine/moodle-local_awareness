@local @local_awareness @javascript
Feature: Previewing a notice from the editor
  In order to judge a notice before anybody else is shown it
  As an administrator
  I need to see the notice itself, not a drawing of it

  Background:
    Given I log in as "admin"
    And I navigate to "Awareness > Manage" in site administration
    And I click on "Create new notice" "link"

  Scenario: The preview shows the content the form currently holds
    # It used to draw a mock of the notice modal INSIDE a core modal, showing truncated plain text
    # with the formatting stripped — a picture of a dialogue, inside a dialogue, that had to be kept
    # in step with the real thing by hand. The content itself is the preview now, in the same
    # core/modal_cancel the manage list opens for a saved notice.
    When I set the field "Title" to "Scheduled maintenance"
    And I set the field "Content" to "The library closes at six."
    And I click on "Preview" "button"
    Then I should see "The library closes at six." in the "Scheduled maintenance" "dialogue"

  Scenario: An empty notice says so rather than showing an empty box
    When I click on "Preview" "button"
    Then I should see "This notice has no content yet."

  Scenario: The dialogue closes on Escape
    # Escape, the focus trap and the return of focus to the button that opened it all come from
    # core/modal_cancel. Asserted so that replacing it with a hand-made dialogue cannot be a silent
    # change: a hand-made one passes every other gate in the pipeline.
    When I set the field "Title" to "Scheduled maintenance"
    And I set the field "Content" to "The library closes at six."
    And I click on "Preview" "button"
    Then I should see "The library closes at six."
    When I press the escape key
    Then I should not see "The library closes at six."
