@local @local_awareness @javascript
Feature: The notice editor's collapsible sections
  In order to fill in a notice without hunting for fields
  As an administrator
  I need every field to be on the page and the optional sections one click away

  Background:
    Given I log in as "admin"
    And I navigate to "Awareness > Manage" in site administration
    And I click on "Create new notice" "link"

  Scenario: Every field of the form is on the page
    # This is the regression the whole editor rebuild exists for. The old editor hid the moodleform
    # in a clipped 1x1 container and moved its rendered rows into cards with JavaScript, driven by a
    # map of field names; anything the map forgot stayed in that container — focusable and announced
    # by a screen reader, but painted nowhere. Role context was one of the two that were lost, which
    # is why it is named here rather than left to the section headings.
    When I click on "Expand all" "link"
    Then I should see "Role context"
    And I should see "Apply to URL match"
    And I should see "Modal width"

  Scenario: Each section of the form is present
    Then I should see "Notice content"
    And I should see "Behaviour"
    And I should see "Audience"
    And I should see "Display restrictions"
    And I should see "Modal appearance"

  Scenario: A malformed modal width is rejected
    # The rule is a client-side one, so the form never posts and the message appears in place. The
    # server-side counterpart — core expanding whichever section holds an error — comes free with
    # the form declaring its own sections (formslib.php, setExpanded($header, true, true)); it needs
    # no code here and cannot be provoked through a rule that stops the submit in the browser.
    When I click on "Expand all" "link"
    And I set the field "Title" to "Notice with a bad width"
    And I set the field "Modal width" to "not-a-length"
    And I click on "Save changes" "button"
    Then I should see "Use a number followed by px"
