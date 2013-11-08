<?php


/**
 * The main classes for the multichoice question type.
 *
 * These inherit or implement code found in quiz_question.classes.inc.
 *
 * Sponsored by: Norwegian Centre for Telemedicine
 * Code: falcon
 *
 * Based on:
 * Other question types in the quiz framework.
 *
 *
 *
 * @file
 * Question type, enabling the creation of multiple choice and multiple answer questions.
 * Contains \Drupal\multichoice\MultichoiceQuestion.
 */

namespace Drupal\multichoice;

use Drupal\quiz_question\QuizQuestion;

/**
 * Extension of QuizQuestion.
 */
class MultichoiceQuestion extends QuizQuestion {

  /**
   * Forgive some possible logical flaws in the user input.
   */
  private function forgive() {
    if ($this->node->choice_multi == 1) {
      for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
        $short = &$this->node->alternatives[$i];
        // If the scoring data doesn't make sense, use the data from the "correct" checkbox to set the score data
        if ($short['score_if_chosen'] == $short['score_if_not_chosen']
         || !is_numeric($short['score_if_chosen'])
         || !is_numeric($short['score_if_not_chosen'])) {
          if ($short['correct'] == 1) {
            $short['score_if_chosen'] = 1;
            $short['score_if_not_chosen'] = 0;
          }
          else {
            if (variable_get('multichoice_def_scoring', 0) == 0) {
              $short['score_if_chosen'] = -1;
              $short['score_if_not_chosen'] = 0;
            }
            elseif (variable_get('multichoice_def_scoring', 0) == 1) {
              $short['score_if_chosen'] = 0;
              $short['score_if_not_chosen'] = 1;
            }
          }
        }
      }
    }
    else {
      // For questions with one, and only one, correct answer, there will be no points awarded for alternatives
      // not chosen.
      for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
        $short = &$this->node->alternatives[$i];
        $short['score_if_not_chosen'] = 0;
        if (isset($short['correct']) && $short['correct'] == 1 && !_quiz_is_int($short['score_if_chosen'], 1)) {
          $short['score_if_chosen'] = 1;
        }
      }
    }
  }

  /**
   * Warn the user about possible user errors
   */
  private function warn() {
    // Count the number of correct answers
    $num_corrects = 0;
    for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
      $alt = &$this->node->alternatives[$i];
      if ($alt['score_if_chosen'] > $alt['score_if_not_chosen']) {
        $num_corrects++;
      }
    }
    if ($num_corrects == 1 && $this->node->choice_multi == 1 || $num_corrects > 1 && $this->node->choice_multi == 0) {
      $link_options = array();
      if (isset($_GET['destination'])) {
        $link_options['query'] = array('destination' => $_GET['destination']);
      }
      $go_back = l(t('go back'), 'node/' . $this->node->nid . '/edit', $link_options);
      if ($num_corrects == 1) {
        drupal_set_message(
          t('Your question allows multiple answers. Only one of the alternatives have been marked as correct. If this wasn\'t intended please !go_back and correct it.',
            array('!go_back' => $go_back)),
          'warning');
      }
      else {
        drupal_set_message(
          t('Your question doesn\'t allow multiple answers. More than one of the alternatives have been marked as correct. If this wasn\'t intended please !go_back and correct it.',
            array('!go_back' => $go_back)),
          'warning');
      }
    }
  }

  /**
   * Run check_markup() on the field of the specified choice alternative
   * @param $alternativeIndex
   *  The index of the alternative in the alternatives array.
   * @param $field
   *  The name of the field we want to check markup on
   * @param $check_user_access
   *  Whether or not to check for user access to the filter we're trying to apply
   * @return HTML markup
   */
  private function checkMarkup($alternativeIndex, $field, $check_user_access = FALSE) {
    $alternative = $this->node->alternatives[$alternativeIndex];
    return check_markup($alternative[$field]['value'], $alternative[$field]['format']);
  }

  /**
   * Implementation of save
   *
   * Stores the question in the database.
   *
   * @param is_new if - if the node is a new node...
   * (non-PHPdoc)
   * @see sites/all/modules/quiz-HEAD/question_types/quiz_question/QuizQuestion#save()
   */
  public function saveNodeProperties($is_new = FALSE) {
    $is_new = $is_new || $this->node->revision == 1;

    // Before we save we forgive some possible user errors
    $this->forgive();

    // We also add warnings on other possible user errors
    $this->warn();

    if ($is_new) {
      $id = db_insert('quiz_multichoice_properties')
        ->fields(array(
          'nid' => $this->node->nid,
          'vid' => $this->node->vid,
          'choice_multi' => $this->node->choice_multi,
          'choice_random' => $this->node->choice_random,
          'choice_boolean' => $this->node->choice_boolean,
        ))
        ->execute();

      // TODO: utilize the benefit of multiple insert of DBTNG
      for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
        if (drupal_strlen($this->node->alternatives[$i]['answer']['value']) > 0) {
          $this->insertAlternative($i);
        }
      }
    }
    else {
      db_update('quiz_multichoice_properties')
        ->fields(array(
          'choice_multi' => $this->node->choice_multi,
          'choice_random' => $this->node->choice_random,
          'choice_boolean' => $this->node->choice_boolean,
        ))
        ->condition('nid', $this->node->nid)
        ->condition('vid', $this->node->vid)
        ->execute();

      // We fetch ids for the existing answers belonging to this question
      // We need to figure out if an existing alternative has been changed or deleted.
      $res = db_query('SELECT id FROM {quiz_multichoice_answers}
              WHERE question_nid = :nid AND question_vid = :vid', array(':nid' => $this->node->nid, ':vid' => $this->node->vid));

      // We start by assuming that all existing alternatives needs to be deleted
      $ids_to_delete = array();
      while ($res_o = $res->fetch()) {
        $ids_to_delete[] = $res_o->id;
      }

      for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
        $short = $this->node->alternatives[$i];
        if (drupal_strlen($this->node->alternatives[$i]['answer']['value']) > 0) {
          // If new alternative
          if (!is_numeric($short['id'])) {
            $this->insertAlternative($i);
          }
          // If existing alternative
          else {
            $this->updateAlternative($i);
            // Make sure this alternative isn't deleted
            $key = array_search($short['id'], $ids_to_delete);
            $ids_to_delete[$key] = FALSE;
          }
        }
      }
      foreach ($ids_to_delete as $id_to_delete) {
        if ($id_to_delete) {
          db_delete('quiz_multichoice_answers')
            ->condition('id', $id_to_delete)
            ->execute();
        }
      }
    }
    $this->saveUserSettings();
  }

  /**
   * Helper function. Saves new alternatives
   *
   * @param $i
   *  The alternative index
   */
  private function insertAlternative($i) {
    $alternatives = $this->node->alternatives[$i];
    db_insert('quiz_multichoice_answers')
      ->fields(array(
        'answer' => $alternatives['answer']['value'],
        'answer_format' => $alternatives['answer']['format'],
        'feedback_if_chosen' => $alternatives['feedback_if_chosen']['value'],
        'feedback_if_chosen_format' => $alternatives['feedback_if_chosen']['format'],
        'feedback_if_not_chosen' => $alternatives['feedback_if_not_chosen']['value'],
        'feedback_if_not_chosen_format' => $alternatives['feedback_if_not_chosen']['format'],
        'score_if_chosen' => $alternatives['score_if_chosen'],
        'score_if_not_chosen' => $alternatives['score_if_not_chosen'],
        'question_nid' => $this->node->nid,
        'question_vid' => $this->node->vid
      ))
      ->execute();
  }

  /**
   * Helper function. Updates existing alternatives
   *
   * @param $i
   *  The alternative index
   */
  private function updateAlternative($i) {
    $short = $this->node->alternatives[$i];
    db_update('quiz_multichoice_answers')
      ->fields(array(
        'answer' => $short['answer']['value'],
        'answer_format' => $short['answer']['format'],
        'feedback_if_chosen' => $short['feedback_if_chosen']['value'],
        'feedback_if_chosen_format' => $short['feedback_if_chosen']['format'],
        'feedback_if_not_chosen' => $short['feedback_if_not_chosen']['value'],
        'feedback_if_not_chosen_format' => $short['feedback_if_not_chosen']['format'],
        'score_if_chosen' => $short['score_if_chosen'],
        'score_if_not_chosen' => $short['score_if_not_chosen'],
      ))
      ->condition('id', $short['id'])
      ->condition('question_nid', $this->node->nid)
      ->condition('question_vid', $this->node->vid)
      ->execute();
  }

  /**
   * Implementation of validate
   *
   * QuizQuestion#validate()
   */
  public function validateNode(array &$form) {
    if ($this->node->choice_multi == 0) {
      $found_one_correct = FALSE;
      for ($i = 0; (isset($this->node->alternatives[$i]) && is_array($this->node->alternatives[$i])); $i++) {
        $short = $this->node->alternatives[$i];
        if (drupal_strlen($this->checkMarkup($i, 'answer')) < 1) {
          continue;
        }
        if ($short['correct'] == 1) {
          if ($found_one_correct) {
            // We don't display an error message here since we allow alternatives to be partially correct
          }
          else {
            $found_one_correct = TRUE;
          }
        }
      }
      if (!$found_one_correct) {
        form_set_error('choice_multi', t('You have not marked any alternatives as correct. If there are no correct alternatives you should allow multiple answers.'));
      }
    }
    else {
      for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
        $short = $this->node->alternatives[$i];
        if (strlen($this->checkMarkup($i, 'answer')) < 1) {
          continue;
        }
        if ($short['score_if_chosen'] < $short['score_if_not_chosen'] && $short['correct']) {
          form_set_error("alternatives][$i][score_if_not_chosen", t('The alternative is marked as correct, but gives more points if you don\'t select it.'));
        }
        elseif ($short['score_if_chosen'] > $short['score_if_not_chosen'] && !$short['correct']) {
          form_set_error("alternatives][$i][score_if_chosen", t('The alternative is not marked as correct, but gives more points if you select it.'));
        }
      }
    }
  }

  /**
   * Implementation of delete
   *
   * @see QuizQuestion#delete()
   */
  public function delete($only_this_version = FALSE) {
    $delete_properties = db_delete('quiz_multichoice_properties')->condition('nid', $this->node->nid);
    $delete_answers = db_delete('quiz_multichoice_answers')->condition('question_nid', $this->node->nid);
    $delete_results = db_delete('quiz_multichoice_user_answers')->condition('question_nid', $this->node->nid);

    if ($only_this_version) {
      $delete_properties->condition('vid', $this->node->vid);
      $delete_answers->condition('question_vid', $this->node->vid);
      $delete_results->condition('question_vid', $this->node->vid);
    }

    // Delete from table quiz_multichoice_user_answer_multi
    if ($only_this_version) {
      $user_answer_id = db_query('SELECT id FROM {quiz_multichoice_user_answers} WHERE question_nid = :nid AND question_vid = :vid', array(':nid' => $this->node->nid, ':vid' => $this->node->vid))->fetchCol();
    }
    else {
      $user_answer_id = db_query('SELECT id FROM {quiz_multichoice_user_answers} WHERE question_nid = :nid', array(':nid' => $this->node->nid))->fetchCol();
    }

    db_delete('quiz_multichoice_user_answer_multi')
      ->condition('user_answer_id', $user_answer_id, 'IN')
      ->execute();

    $delete_properties->execute();
    $delete_answers->execute();
    $delete_results->execute();
    parent::delete($only_this_version);
  }

  /**
   * Implementation of getNodeProperties
   *
   * @see QuizQuestion#getNodeProperties()
   */
  public function getNodeProperties() {
    if (isset($this->nodeProperties) && !empty($this->nodeProperties)) {
      return $this->nodeProperties;
    }
    $props = parent::getNodeProperties();

    $res_a = db_query('SELECT choice_multi, choice_random, choice_boolean FROM {quiz_multichoice_properties}
            WHERE nid = :nid AND vid = :vid', array(':nid' => $this->node->nid, ':vid' => $this->node->vid))->fetchAssoc();

    if (is_array($res_a)) {
      $props = array_merge($props, $res_a);
    }

    // Load the answers
    $res = db_query('SELECT id, answer, answer_format, feedback_if_chosen, feedback_if_chosen_format,
            feedback_if_not_chosen, feedback_if_not_chosen_format, score_if_chosen, score_if_not_chosen
            FROM {quiz_multichoice_answers}
            WHERE question_nid = :question_nid AND question_vid = :question_vid
            ORDER BY id', array(':question_nid' => $this->node->nid, ':question_vid' => $this->node->vid));
    $props['alternatives'] = array(); // init array so it can be iterated even if empty
    while ($res_arr = $res->fetchAssoc()) {
      $props['alternatives'][] = $res_arr;
    }
    $this->nodeProperties = $props;
    return $props;
  }

  /**
   * Implementation of getNodeView
   *
   * @see QuizQuestion#getNodeView()
   */
  public function getNodeView() {
    $content = parent::getNodeView();
    if ($this->node->choice_random) {
      $this->shuffle($this->node->alternatives);
    }
    $content['answers'] = array(
       '#markup' => theme('multichoice_answer_node_view', array('alternatives' => $this->node->alternatives, 'show_correct' => $this->viewCanRevealCorrect())),
       '#weight' => 2,
    );

    return $content;
  }

  /**
   * Generates the question form.
   *
   * This is called whenever a question is rendered, either
   * to an administrator or to a quiz taker.
   */
  public function getAnsweringForm(array $form_state = NULL, $rid) {
    $form = parent::getAnsweringForm($form_state, $rid);
    //$form['#theme'] = 'multichoice_answering_form';

    /* We use an array looking key to be able to store multiple answers in tries.
     * At the moment all user answers have to be stored in tries. This is something we plan
     * to fix in quiz 5.x.
     */
    $form['tries[answer]'] = array(
      '#options' => array(),
      '#theme' => 'multichoice_alternative',
    );
    if (isset($rid)) {
      // This question has already been answered. We load the answer.
      $response = new MultichoiceResponse($rid, $this->node);
    }
    for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
      $short = $this->node->alternatives[$i];
      $answer_markup = check_markup($short['answer'], $short['answer_format']);
      if (drupal_strlen($answer_markup) > 0) {
        $form['tries[answer]']['#options'][$short['id']] = $answer_markup;
      }
    }
    if ($this->node->choice_random) {
      // We save the choice order so that the order will be the same in the answer report
      $form['tries[choice_order]'] = array(
        '#type' => 'hidden',
        '#value' => implode(',', $this->shuffle($form['tries[answer]']['#options'])),
      );
    }
    if ($this->node->choice_multi) {
      $form['tries[answer]']['#type'] = 'checkboxes';
      $form['tries[answer]']['#title'] = t('Choose');
      if (isset($response)) {
        if (is_array($response->getResponse())) {
          $form['tries[answer]']['#default_value'] = $response->getResponse();
        }
      }
    }
    else {
      $form['tries[answer]']['#type'] = 'radios';
      $form['tries[answer]']['#title'] = t('Choose one');
      if (isset($response)) {
        $selection = $response->getResponse();
        if (is_array($selection)) {
          $form['tries[answer]']['#default_value'] = array_pop($selection);
        }
      }
    }

    return $form;
  }

  /**
   * Custom shuffle function. It keeps the array key - value relationship intact
   *
   * @param array $array
   * @return unknown_type
   */
  private function shuffle(array &$array) {
    $newArray = array();
    $toReturn = array_keys($array);
    shuffle($toReturn);
    foreach ($toReturn as $key) {
      $newArray[$key] = $array[$key];
    }
    $array = $newArray;
    return $toReturn;
  }

  /**
   * Implementation of getCreationForm
   *
   * @see QuizQuestion#getCreationForm()
   */
  public function getCreationForm(array &$form_state = NULL) {
    $form = array();
    $node_types = node_type_get_types();    
    $type = $node_types[$this->node->type];
    // We add #action to the form because of the use of ajax
    $options = array();
    $get = $_GET;
    unset($get['q']);
    if (!empty($get)) {
      $options['query'] = $get;
    }

    // TODO The second parameter to this function call should be an array.
    $action = url('node/add/' . $type->type, $options);
    if (isset($this->node->nid)) {
      // TODO The second parameter to this function call should be an array.
      $action = url('node/' . $this->node->nid . '/edit', $options);
    }
    $form['#action'] = $action;

    $form['alternatives'] = array(
      '#type' => 'fieldset',
      '#title' => t('Answer'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#weight' => -4,
      '#tree' => TRUE,
    );

    // Get the nodes settings, users settings or default settings
    $default_settings = $this->getDefaultAltSettings();

    $form['alternatives']['settings'] = array(
      '#type' => 'fieldset',
      '#title' => t('Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => t('Your settings will be remembered.'),
    );
    $form['alternatives']['settings']['choice_multi'] = array(
      '#type' => 'checkbox',
      '#title' => t('Multiple answers'),
      '#description' => t('Allow any number of answers(checkboxes are used). If this box is not checked, one, and only one answer is allowed(radiobuttons are used).'),
      '#default_value' => $default_settings['choice_multi'],
      '#parents' => array('choice_multi'),
    );
    $form['alternatives']['settings']['choice_random'] = array(
      '#type' => 'checkbox',
      '#title' => t('Random order'),
      '#description' => t('Present alternatives in random order when quiz is being taken.'),
      '#default_value' => $default_settings['choice_random'],
      '#parents' => array('choice_random'),
    );
    $form['alternatives']['settings']['choice_boolean'] = array(
      '#type' => 'checkbox',
      '#title' => t('Simple scoring'),
      '#description' => t('Give max score if everything is correct. Zero points otherwise.'),
      '#default_value' => $default_settings['choice_boolean'],
      '#parents' => array('choice_boolean'),
    );

    // Add helper tag where we will place the input selector for all the textareas.
    $form['alternatives']['input_format_all'] = array(
      '#markup' => '<DIV id="input-all-ph"></DIV>',
    );

    $form['alternatives']['#theme'][] = 'multichoice_creation_form';
    $i = 0;

    // choice_count might be stored in the form_state after an ajax callback
    if (isset($form_state['choice_count'])) {
      $choice_count = $form_state['choice_count'];
    }
    else {
      $choice_count = max(variable_get('multichoice_def_num_of_alts', 2), isset($this->node->alternatives) ? count($this->node->alternatives) : 0);
    }

    for (; $i < $choice_count; $i++) {
      $short = isset($this->node->alternatives[$i]) ? $this->node->alternatives[$i] : NULL;
      $form['alternatives'][$i] = array(
        '#type' => 'fieldset',
        '#title' => t('Alternative !i', array('!i' => ($i + 1))),
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
        // - The two first alternatives won't be collapsed.
        // - Populated alternatives won't be collapsed
      );
      $form['alternatives'][$i]['#theme'][] = 'multichoice_alternative_creation';

      if (is_array($short)) {
        if ($short['score_if_chosen'] == $short['score_if_not_chosen']) {
          $correct_default = isset($short['correct']) ? $short['correct'] : FALSE;
        }
        else {
          $correct_default = $short['score_if_chosen'] > $short['score_if_not_chosen'];
        }
      }
      else {
        $correct_default = FALSE;
      }
      $form['alternatives'][$i]['correct'] = array(
        '#type' => 'checkbox',
        '#title' => t('Correct'),
        '#default_value' => $correct_default,
        '#attributes' => array('onchange' => 'Multichoice.refreshScores(this, ' . variable_get('multichoice_def_scoring', 0) . ')'),
      );
      // We add id to be able to update the correct alternatives if the node is updated, without destroying
      // existing answer reports
      $form['alternatives'][$i]['id'] = array(
        '#type' => 'value',
        '#value' => $short['id'],
      );

      $form['alternatives'][$i]['answer'] = array(
        '#type' => 'text_format',
        '#base_type' => 'textarea',
        '#title' => t('Alternative !i', array('!i' => ($i + 1))),
        '#default_value' => $short['answer'],
        '#required' => $i < 2,
        '#format' => isset($short['answer_format']) ? $short['answer_format'] : NULL,
        '#rows' => 3,
      );

      $form['alternatives'][$i]['advanced'] = array(
        '#type' => 'fieldset',
        '#title' => t('Advanced options'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
      );
      $form['alternatives'][$i]['advanced']['feedback_if_chosen'] = array(
        '#type' => 'text_format',
        '#base_type' => 'textarea',
        '#title' => t('Feedback if chosen'),
        '#description' => t('This feedback is given to users who chooses this alternative.'),
        '#parents' => array('alternatives', $i, 'feedback_if_chosen'),
        '#default_value' => $short['feedback_if_chosen'],
        '#format' => isset($short['feedback_if_chosen_format']) ? $short['feedback_if_chosen_format'] : NULL,
        '#rows' => 3,
      );
      // We add 'helper' to trick the current version of the wysiwyg module to add an editor to several
      // textareas in the same fieldset
      $form['alternatives'][$i]['advanced']['helper']['feedback_if_not_chosen'] = array(
        '#type' => 'text_format',
        '#base_type' => 'textarea',
        '#title' => t('Feedback if not chosen'),
        '#description' => t('This feedback is given to users who doesn\'t choose this alternative.'),
        '#parents' => array('alternatives', $i, 'feedback_if_not_chosen'),
        '#default_value' => $short['feedback_if_not_chosen'],
        '#format' => isset($short['feedback_if_not_chosen_format']) ? $short['feedback_if_not_chosen_format'] : NULL,
        '#rows' => 3,
      );
      $default_value = isset($this->node->alternatives[$i]['score_if_chosen']) ? $this->node->alternatives[$i]['score_if_chosen'] : 0;
      $form['alternatives'][$i]['advanced']['score_if_chosen'] = array(
        '#type' => 'textfield',
        '#title' => t('Score if chosen'),
        '#size' => 4,
        '#maxlength' => 4,
        '#default_value' => $default_value,
        '#description' => t('This score is added to the users total score if the user chooses this alternative.'),
        '#attributes' => array(
          'onkeypress' => 'Multichoice.refreshCorrect(this)',
          'onkeyup' => 'Multichoice.refreshCorrect(this)',
          'onchange' => 'Multichoice.refreshCorrect(this)'
        ),
        '#parents' => array('alternatives', $i, 'score_if_chosen')
      );

      $default_value = $short['score_if_not_chosen'];
      if (!isset($default_value)) {
        $default_value = '0';
      }
      $form['alternatives'][$i]['advanced']['score_if_not_chosen'] = array(
        '#type' => 'textfield',
        '#title' => t('Score if not chosen'),
        '#size' => 4,
        '#maxlength' => 4,
        '#default_value' => $default_value,
        '#description' => t('This score is added to the users total score if the user doesn\'t choose this alternative. Only used if multiple answers are allowed.'),
        '#attributes' => array(
          'onkeypress' => 'Multichoice.refreshCorrect(this)',
          'onkeyup' => 'Multichoice.refreshCorrect(this)',
          'onchange' => 'Multichoice.refreshCorrect(this)'
        ),
        '#parents' => array('alternatives', $i, 'score_if_not_chosen')
      );
    }
    // ahah helper tag. New questions will be inserted before this tag
    $form['alternatives']["placeholder"] = array(
      '#markup' => '<div id="placeholder"></div>',
    );

    // We can't send the get values to the ahah callback the normal way, so we do it like this.
    $form['get'] = array(
      '#type' => 'value',
      '#value' => $get,
    );

    $form['alternatives']['multichoice_add_alternative'] = array(
      '#type' => 'submit',
      '#value' => t('Add more alternatives'),
      '#submit' => array('multichoice_more_choices_submit'), // If no javascript action.
      '#limit_validation_errors' => array(),
      '#ajax' => array(
        'callback' => 'multichoice_add_alternative_ajax_callback',
        'wrapper' => 'placeholder',
        'effect' => 'slide',
        'method' => 'before',
      ),
    );
    $form['#attached']['js'] = array(
      drupal_get_path('module', 'multichoice') . '/multichoice.js',
    );

    return $form;
  }

  /**
   * Helper function provding the default settings for the creation form.
   *
   * @return
   *  Array with the default settings
   */
  private function getDefaultAltSettings() {
    // If the node is being updated the default settings are those stored in the node
    if (isset($this->node->nid)) {
      $settings['choice_multi'] = $this->node->choice_multi;
      $settings['choice_random'] = $this->node->choice_random;
      $settings['choice_boolean'] = $this->node->choice_boolean;
    }
    // We try to fetch the users settings
    elseif ($settings = $this->getUserSettings()) {
    }
    // The user is creating his first multichoice node
    else {
      $settings['choice_multi'] = 0;
      $settings['choice_random'] = 0;
      $settings['choice_boolean'] = 0;
    }
    return $settings;
  }

  /**
   * Fetches the users default settings for the creation form
   *
   * @return
   *  The users default node settings
   */
  private function getUserSettings() {
    global $user;
    $res = db_query('SELECT choice_multi, choice_boolean, choice_random
            FROM {quiz_multichoice_user_settings}
            WHERE uid = :uid', array(':uid' => $user->uid))->fetchAssoc();
    if ($res) {
      return $res;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Fetches the users default settings from the creation form
   */
  private function saveUserSettings() {
    global $user;
    db_merge('quiz_multichoice_user_settings')
      ->key(array('uid' => $user->uid))
      ->fields(array(
        'choice_random' => $this->node->choice_random,
        'choice_multi' => $this->node->choice_multi,
        'choice_boolean' => $this->node->choice_boolean,
      ))
      ->execute();
  }

  /**
   * Implementation of getMaximumScore.
   *
   * @see QuizQuestion#getMaximumScore()
   */
  public function getMaximumScore() {
    if ($this->node->choice_boolean) {
      return 1;
    }

    $max = 0;
    for ($i = 0; isset($this->node->alternatives[$i]); $i++) {
      $short = $this->node->alternatives[$i];
      if ($this->node->choice_multi) {
        $max += max($short['score_if_chosen'], $short['score_if_not_chosen']);
      }
      else {
        $max = max($max, $short['score_if_chosen'], $short['score_if_not_chosen']);
      }
    }
    return max($max, 1);
  }
}
