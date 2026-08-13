@local @local_awareness
Feature: Repeating notices competing for the same pages are flagged to the author
  In order to notice that two repeating notices will take turns interrupting the same people
  As a site administrator
  I need the notice list to flag them, since editing either one alone gives no sign of the other

  Background:
    Given I change window size to "large"
    And I log in as "admin"

  # Gamma is the control. It repeats exactly like the other two and differs only in the pages it
  # reaches, so a badge that appeared on every repeating notice — or on every row — would fail here
  # rather than pass unnoticed.
  Scenario: The list flags only the repeating notices that reach the same pages
    Given the following site notices exist
      | title | content        | pathmatch         | resetinterval |
      | Alpha | body of alpha  | /my/%             | 86400         |
      | Beta  | body of beta   | /my/%             | 86400         |
      | Gamma | body of gamma  | /user/profile.php | 86400         |
    When I navigate to "Awareness > Manage" in site administration
    Then I should see "Competing" in the "Alpha" "table_row"
    And I should see "Competing" in the "Beta" "table_row"
    And I should not see "Competing" in the "Gamma" "table_row"

  # A site that has just installed the plugin opens this page with nothing on it. table_sql leaves
  # rawdata as null rather than an empty array until the first row is added, so the collision lookup
  # was handed null and the page died — on the one page a new site sees first. Caught by an existing
  # scenario, not by the ones above, every one of which happens to create notices.
  Scenario: The list opens on a site with no notices at all
    When I navigate to "Awareness > Manage" in site administration
    Then I should see "Create new notice"

  # End to end through the web service: the author is told while still choosing, not after saving.
  # The interval is set first and the path second, so the warning can only appear once both halves
  # of the question are answered — which is also what proves the check is reading both fields.
  @javascript
  Scenario: The editor warns about a competing notice while the author is still choosing
    Given the following site notices exist
      | title      | content         | pathmatch | resetinterval |
      | Old rival  | body of rival   | /my/%     | 86400         |
    When I navigate to "Awareness > Manage" in site administration
    And I click on "Create new notice" "link"
    And I wait until ".local-awareness-editor" "css_element" exists
    # Targeted by id: "Reset every" is a duration element, so its label belongs to the group rather
    # than to the number input the check actually reads.
    And I set the field "id_resetinterval_number" to "1"
    And I set the field "id_pathmatch" to "/my/%"
    Then I should see "Old rival"

  # A notice shown once takes its turn and leaves, so it competes with nobody however it is aimed.
  Scenario: A notice that does not repeat is never flagged
    Given the following site notices exist
      | title   | content         | pathmatch | resetinterval |
      | Repeats | body of repeats | /my/%     | 86400         |
      | Once    | body of once    | /my/%     | 0             |
    When I navigate to "Awareness > Manage" in site administration
    Then I should not see "Competing" in the "Once" "table_row"
    And I should not see "Competing" in the "Repeats" "table_row"
