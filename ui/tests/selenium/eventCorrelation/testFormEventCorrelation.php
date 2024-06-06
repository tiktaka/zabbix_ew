<?php
/*
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once dirname(__FILE__).'/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../behaviors/CMessageBehavior.php';
require_once dirname(__FILE__).'/../behaviors/CTableBehavior.php';

use Facebook\WebDriver\WebDriverBy;

/**
 * @backup correlation
 *
 * @onBefore prepareData
 */
class testFormEventCorrelation extends CWebTest {
	const HASH_SQL =  'SELECT * FROM correlation c INNER JOIN corr_condition cc ON c.correlationid = cc.correlationid'.
			' LEFT JOIN corr_condition_group ccg ON cc.corr_conditionid = ccg.corr_conditionid'.
			' LEFT JOIN corr_condition_tag cct ON cc.corr_conditionid = cct.corr_conditionid'.
			' LEFT JOIN corr_condition_tagpair cctp ON cc.corr_conditionid = cctp.corr_conditionid'.
			' LEFT JOIN corr_condition_tagvalue cctv ON cc.corr_conditionid = cctv.corr_conditionid'.
			' ORDER BY cc.corr_conditionid';

	/**
	 * Attach MessageBehavior and TableBehaviour to the test.
	 *
	 * @return array
	 */
	public function getBehaviors() {
		return [
			CMessageBehavior::class,
			CTableBehavior::class
		];
	}

	public function prepareData() {
		CDataHelper::call('correlation.create', [
			[
				'name' => 'Event correlation for layout check',
				'description' => 'Test description layout',
				'filter' => [
					'evaltype' => CONDITION_EVAL_TYPE_EXPRESSION,
					'formula' => 'A or B',
					'conditions' => [
						['type' => ZBX_CORR_CONDITION_OLD_EVENT_TAG, 'tag' => 'tag', 'formulaid' => 'A'],
						['type' => ZBX_CORR_CONDITION_NEW_EVENT_TAG, 'tag' => 'tag', 'formulaid' => 'B']
					]
				],
				'operations' => [['type' => ZBX_CORR_OPERATION_CLOSE_OLD]]
			],
			[
				'name' => 'Event correlation for delete',
				'description' => 'Test description delete',
				'filter' => [
					'evaltype' => CONDITION_EVAL_TYPE_AND_OR,
					'conditions' => [['type' => ZBX_CORR_CONDITION_OLD_EVENT_TAG, 'tag' => 'delete tag']]
				],
				'operations' => [['type' => ZBX_CORR_OPERATION_CLOSE_OLD]]
			],
			[
				'name' => 'Event correlation for cancel',
				'description' => 'Test description cancel',
				'filter' => [
					'evaltype' => CONDITION_EVAL_TYPE_AND_OR,
					'conditions' => [
						['type' => ZBX_CORR_CONDITION_OLD_EVENT_TAG, 'tag' => 'cancel tag'],
						['type' => ZBX_CORR_CONDITION_NEW_EVENT_TAG, 'tag' => 'cancel tag']
					]
				],
				'operations' => [['type' => ZBX_CORR_OPERATION_CLOSE_OLD]],
				'status' => ZBX_CORRELATION_DISABLED
			],
			[
				'name' => 'Event correlation for clone',
				'description' => 'Test description clone',
				'filter' => [
					'evaltype' => CONDITION_EVAL_TYPE_AND_OR,
					'conditions' => [['type' => ZBX_CORR_CONDITION_OLD_EVENT_TAG, 'tag' => 'clone tag']]
				],
				'operations' => [['type' => ZBX_CORR_OPERATION_CLOSE_OLD]]
			]
		]);
	}

	/**
	 * Test the layout and basic functionality of the form.
	 */
	public function testFormEventCorrelation_Layout() {
		$this->page->login()->open('zabbix.php?action=correlation.list')->waitUntilReady();
		$this->query('button:Create correlation')->one()->click();
		$this->page->waitUntilReady();
		$this->page->assertHeader('Event correlation rules');
		$form = $this->page->query('id:correlation.edit')->one()->asForm();

		// Check form labels.
		$this->assertEquals(['Name', 'Type of calculation', 'Conditions', 'Description', 'Operations', '', 'Enabled'],
				$form->getLabels()->asText()
		);

		// Check form inputs to be enabled.
		foreach (['name', 'description'] as $id) {
			$this->assertTrue($form->query('id', $id)->one()->isEnabled());
		}

		// Check mandatory fields.
		$this->assertEquals(['Name', 'Conditions'], $form->getRequiredLabels());

		// Check input attributes.
		$field_attributes = [
			'Name' => ['type' => 'text', 'maxlength' => 255, 'value' => '', 'autofocus' => 'true'],
			'id:evaltype' => ['value' => 0],
			'id:formula' => ['type' => 'text', 'value' => '', 'maxlength' => 255, 'placeholder' => 'A or (B and C) ...',
					'disabled' => 'true'],
			'Description' => ['maxlength' => 65535, 'value' => '', 'rows' => 7]
		];

		foreach ($field_attributes as $field => $attributes) {
			$field_element = $form->getField($field);

			foreach ($attributes as $attribute => $expected_value) {
				$this->assertEquals($expected_value, $field_element->getAttribute($attribute));
			}
		}

		// Check that Type of calculation field is hidden.
		foreach (['evaltype', 'formula'] as $id) {
			$this->assertFalse($form->getField('id:'.$id)->isVisible());
		}

		// Check Conditions table.
		$conditions_table = $form->query('id:condition_table')->asTable()->one();
		$this->assertEquals(['Label', 'Name', 'Action'], $conditions_table->getHeaders()->asText());
		$this->assertTrue($conditions_table->query('button:Add')->one()->isClickable());

		// Check Operations checkbox list.
		$operations_checkbox_list = $form->getField('Operations')->asCheckboxList();
		$this->assertEquals(['Close old events', 'Close new events'], $operations_checkbox_list->getLabels()->asText());
		$this->assertEquals([], $operations_checkbox_list->getValue());
		$this->assertTrue($operations_checkbox_list->isEnabled());

		// Check that "one operation must be selected" text exists.
		$this->assertTrue($form->query('xpath:.//label[text()="At least one operation must be selected."]')->one()
				->hasClass('form-label-asterisk')
		);

		// Assert Enabled checkbox.
		$enabled_checkbox = $form->getField('Enabled');
		$this->assertTrue($enabled_checkbox->isEnabled());
		$this->assertEquals(true, $enabled_checkbox->getValue());

		// Check form buttons.
		$this->assertEquals(['Add', 'Cancel'], $form->query('class:tfoot-buttons')->one()->query('button')->all()
				->filter(CElementFilter::CLICKABLE)->asText()
		);

		// Open 'New condition' modal.
		$form->getField('Conditions')->query('button:Add')->one()->click();
		$condition_dialog = COverlayDialogElement::find()->waitUntilReady()->all()->last();
		$this->assertEquals('New condition', $condition_dialog->getTitle());
		$condition_form = $condition_dialog->query('id:popup.condition')->asForm()->one();

		// Check available operator types for each condition type.
		$condition_types = [
			'Old event tag name' => [
				'Operator' => ['value' => 'equals'],
				'Tag' => ['value' => '', 'maxlength' => 255, 'required' => true]
			],
			'New event tag name' => [
				'Operator' => ['value' => 'equals'],
				'Tag' => ['value' => '', 'maxlength' => 255, 'required' => true]
			],
			'New event host group' => [
				'Operator' => ['value' => 'equals', 'labels' => ['equals', 'does not equal']],
				'Host groups' => ['value' => '', 'required' => true]
			],
			'Event tag pair' => [
				'Old tag name' => ['value' => '', 'maxlength' => 255, 'required' => true],
				'Operator' => ['value' => 'equals'],
				'New tag name' => ['value' => '', 'maxlength' => 255, 'required' => true]
			],
			'Old event tag value' => [
				'Tag' => ['value' => '', 'maxlength' => 255, 'required' => true],
				'Operator' => ['value' => 'equals', 'labels' => ['equals', 'does not equal', 'contains', 'does not contain']],
				'Value' => ['value' => '', 'maxlength' => 255]
			],
			'New event tag value' => [
				'Tag' => ['value' => '', 'maxlength' => 255, 'required' => true],
				'Operator' => ['value' => 'equals', 'labels' => ['equals', 'does not equal', 'contains', 'does not contain']],
				'Value' => ['value' => '', 'maxlength' => 255]
			]
		];

		foreach ($condition_types as $condition_type => $fields) {
			$condition_form->fill(['Type' => CFormElement::RELOADABLE_FILL($condition_type)]);

			foreach ($fields as $field_label => $properties) {
				$field = $condition_form->getField($field_label);
				$this->assertEquals($properties['value'], $field->getValue());
				$this->assertEquals(CTestArrayHelper::get($properties, 'required'), $condition_form->isRequired($field_label));
				$this->assertEquals(CTestArrayHelper::get($properties, 'maxlength'), $field->getAttribute('maxlength'));

				if (array_key_exists('labels', $properties)) {
					$this->assertEquals($properties['labels'], $field->getLabels()->asText());
				}
			}
		}

		// Check modal footer buttons.
		$this->assertEquals(['Add', 'Cancel'], $condition_dialog->getFooter()->query('button')->all()
				->filter(CElementFilter::CLICKABLE)->asText()
		);

		// Close Condition modal.
		$condition_dialog->query('xpath:.//button[@title="Close"]')->one()->click();
		$condition_dialog->waitUntilNotVisible();

		// Check Type of calculation and Formula fields are enabled.
		$this->page->login()->open('zabbix.php?action=correlation.list')->waitUntilReady();
		$this->query('link:Event correlation for layout check')->one()->click();
		$this->page->waitUntilReady();
		$form->invalidate();

		foreach (['evaltype', 'formula'] as $id) {
			$field = $form->getField('id:'.$id);
			$this->assertTrue($field->isVisible() && $field->isEnabled());
		}

		// Check that formula field becomes hidden when changing Type of calculation.
		$form->fill(['id:evaltype' => 'And']);
		$this->assertFalse($form->getField('id:formula')->isVisible());
	}

	public function getEventCorrelationData() {
		return [
			// #0
			[
				[
					'fields' => [
						'Name' => 'All fields',
						'Description' => 'Event correlation with description',
						'Enabled' => false,
						'Close old events' => true,
						'Close new events' => true
					],
					'update_name' => true,
					'unique_name' => true,
					'remove_condition' => true,
					'conditions' => [
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag'
						]
					]
				]
			],
			// #1
			[
				[
					'fields' => [
						'Name' => 'Minimum fields'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag'
						]
					]
				]
			],
			// #2
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => ''
					],
					'update_name' => true,
					'remove_condition' => true,
					'errors' => [
						'Incorrect value for field "name": cannot be empty.'
					]
				]
			],
			// #3 - Name already used.
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Event correlation for clone'
					],
					'update_name' => true,
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag'
						]
					],
					'errors' => [
						'Correlation "Event correlation for clone" already exists.'
					]
				]
			],
			// #4
			[
				[
					'fields' => [
						'Name' => 'Unicode 🙂🙃 &nbsp; <script>alert("hi!");</script>',
						'Description' => '🙂🙃 &nbsp; <script>alert("hi!");</script>'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => '🙂🙃 &nbsp; <script>alert("hi!");</script>'
						]
					]
				]
			],
			// #5
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Without conditions'
					],
					'remove_condition' => true,
					'errors' => [
						'Invalid parameter "/1/filter/conditions": cannot be empty.'
					]
				]
			],
			// #6
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Without operation',
						'Close old events' => false,
						'Close new events' => false
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag'
						]
					],
					'errors' => [
						'Invalid parameter "/1/operations": cannot be empty.'
					]
				]
			],
			// #7
			[
				[
					'fields' => [
						'Name' => STRING_255
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag'
						]
					]
				]
			],
			// #8
			[
				[
					'fields' => [
						'Name' => 'New event host group equals'
					],
					'conditions' => [
						[
							'Type' => 'New event host group',
							'Host groups' => 'Zabbix servers'
						]
					]
				]
			],
			// #9
			[
				[
					'fields' => [
						'Name' => 'New event host group does not equal'
					],
					'conditions' => [
						[
							'Type' => 'New event host group',
							'Operator' => 'does not equal',
							'Host groups' => 'Zabbix servers'
						]
					]
				]
			],
			// #10
			[
				[
					'fields' => [
						'Name' => 'Event tag pair'
					],
					'conditions' => [
						[
							'Type' => 'Event tag pair',
							'Old tag name' => 'Old tag',
							'New tag name' => 'New tag'
						]
					]
				]
			],
			// #11
			[
				[
					'fields' => [
						'Name' => 'Old event tag value equals tag'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #12
			[
				[
					'fields' => [
						'Name' => 'Old event tag value equals Empty'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Value' => ''
						]
					]
				]
			],
			// #13
			[
				[
					'fields' => [
						'Name' => 'Old event tag value does not equal tag'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'does not equal',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #14
			[
				[
					'fields' => [
						'Name' => 'Old event tag value contains tag'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'contains',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #15
			[
				[
					'fields' => [
						'Name' => 'Old event tag value does not contain tag'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'does not contain',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #16
			[
				[
					'fields' => [
						'Name' => 'New event tag value equals tag'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #17
			[
				[
					'fields' => [
						'Name' => 'New event tag value equals Empty'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Value' => ''
						]
					]
				]
			],
			// #18
			[
				[
					'fields' => [
						'Name' => 'New event tag value does not equal tag'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'does not equal',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #19
			[
				[
					'fields' => [
						'Name' => 'New event tag value contains tag'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'contains',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #20
			[
				[
					'fields' => [
						'Name' => 'New event tag value does not contain tag'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'does not contain',
							'Value' => 'TagValue'
						]
					]
				]
			],
			// #21
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty New event tag'
					],
					'conditions' => [
						[
							'Type' => 'New event tag name'
						]
					],
					'condition_error' => 'Incorrect value for field "tag": cannot be empty.'
				]
			],
			// #22
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty New event host group'
					],
					'conditions' => [
						[
							'Type' => 'New event host group'
						]
					],
					'condition_error' => 'Incorrect value for field "groupid": cannot be empty.'
				]
			],
			// #23
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty Old tag in Event tag pair'
					],
					'conditions' => [
						[
							'Type' => 'Event tag pair',
							'New tag name' => 'New tag'
						]
					],
					'condition_error' => 'Incorrect value for field "oldtag": cannot be empty.'
				]
			],
			// #24
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty New tag in Event tag pair'
					],
					'conditions' => [
						[
							'Type' => 'Event tag pair',
							'Old tag name' => 'Old tag'
						]
					],
					'condition_error' => 'Incorrect value for field "newtag": cannot be empty.'
				]
			],
			// #25
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty tag in Old event tag value'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value'
						]
					],
					'condition_error' => 'Incorrect value for field "tag": cannot be empty.'
				]
			],
			// #26
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty tag value (operator contains) in Old event tag value'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'contains'
						]
					],
					'condition_error' => 'Incorrect value for field "value": cannot be empty.'
				]
			],
			// #27
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty tag value (operator does not contain) in Old event tag value'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'does not contain'
						]
					],
					'condition_error' => 'Incorrect value for field "value": cannot be empty.'
				]
			],
			// #28
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty tag in New event tag value'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value'
						]
					],
					'condition_error' => 'Incorrect value for field "tag": cannot be empty.'
				]
			],
			// #29
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty tag value (operator contains) in New event tag value'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'contains'
						]
					],
					'condition_error' => 'Incorrect value for field "value": cannot be empty.'
				]
			],
			// #30
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty tag value (operator does not contain) in New event tag value'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Tag' => 'TagTag',
							'Operator' => 'does not contain'
						]
					],
					'condition_error' => 'Incorrect value for field "value": cannot be empty.'
				]
			],
			// #31
			[
				[
					'fields' => [
						'Name' => 'Calculation AND/OR'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'expected_expression_update' => '(A or B) and C'
				]
			],
			// #32
			[
				[
					'fields' => [
						'Name' => 'Calculation AND/OR - mixed types'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1',
							'custom_order' => 1
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2',
							'custom_order' => 3
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag3',
							'custom_order' => 4
						],
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag4',
							'custom_order' => 2
						]
					],
					'custom_conditions_order' => true,
					'expected_expression' => '(A or D) and (B or C)',
					'expected_expression_update' => '(A or B or E) and (C or D)'
				]
			],
			// #33
			[
				[
					'fields' => [
						'Name' => 'Calculation AND'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'calculation' => 'And',
					'expected_expression' => 'A and B',
					'expected_expression_update' => '(A and B) and C'
				]
			],
			// #34
			[
				[
					'fields' => [
						'Name' => 'Calculation OR'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'calculation' => 'Or',
					'expected_expression' => 'A or B',
					'expected_expression_update' => '(A or B) or C'
				]
			],
			// #35
			[
				[
					'fields' => [
						'Name' => 'Calculation Custom'
					],
					'remove_condition' => true,
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1',
							'custom_order' => 2
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2',
							'custom_order' => 3
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag3',
							'custom_order' => 1
						]
					],
					'custom_conditions_order' => true,
					'calculation' => 'Custom expression',
					'formula' => 'C or (A and not B)'
				]
			],
			// #36
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Empty expression'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => '',
					'errors' => [
						'Invalid parameter "/1/filter/formula": cannot be empty.'
					]
				]
			],
			// #37
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Missing argument'
					],
					'remove_condition' => true,
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						],
						[
							'Type' => 'Old event tag value',
							'Tag' => 'Test tag3',
							'Operator' => 'contains',
							'Value' => 'Value'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => 'A or B',
					'errors' => [
						'Invalid parameter "/1/filter/conditions": incorrect number of conditions.'
					]
				]
			],
			// #38
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Extra argument'
					],
					'remove_condition' => true,
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						],
						[
							'Type' => 'Old event tag value',
							'Tag' => 'Test tag3',
							'Operator' => 'contains',
							'Value' => 'Value'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => '(A or B) and (C or D)',
					'errors' => [
						'Invalid parameter "/1/filter/conditions": incorrect number of conditions.'
					]
				]
			],
			// #39
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Invalid formula'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						],
						[
							'Type' => 'Old event tag value',
							'Tag' => 'Test tag3',
							'Operator' => 'contains',
							'Value' => 'Value'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => 'Invalid formula',
					'errors' => [
						'Invalid parameter "/1/filter/formula": check expression starting from "Invalid formula".'
					]
				]
			],
			// #40
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Case sensitivity in formula'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => 'A and Not B',
					'errors' => [
						'Invalid parameter "/1/filter/formula": check expression starting from "Not B".'
					]
				]
			],
			// #41
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Case sensitivity of first operator in formula'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => 'NOT A and not B',
					'errors' => [
						'Invalid parameter "/1/filter/formula": check expression starting from " A and not B".'
					]
				]
			],
			// #42
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Name' => 'Only NOT in formula'
					],
					'conditions' => [
						[
							'Type' => 'Old event tag name',
							'Tag' => 'Test tag1'
						],
						[
							'Type' => 'New event tag name',
							'Tag' => 'Test tag2'
						]
					],
					'calculation' => 'Custom expression',
					'formula' => 'not A not B',
					'errors' => [
						'Invalid parameter "/1/filter/formula": check expression starting from " not B".'
					]
				]
			],
			// #43
			[
				[
					'fields' => [
						'Name' => 'Unicode tags'
					],
					'conditions' => [
						[
							'Type' => 'New event tag value',
							'Operator' => 'does not contain',
							'Tag' => '🙂🙃 &nbsp; <script>alert("hi!");</script>',
							'Value' => '🙂🙃 &nbsp; <script>alert("hi!");</script>'
						]
					]
				]
			]
		];
	}

	/**
	 * Test creation of an Event Correlation.
	 *
	 * @dataProvider getEventCorrelationData
	 */
	public function testFormEventCorrelation_Create($data) {
		$this->checkCreateUpdate($data);
	}

	/**
	 * Test opening an Event Correlation for edit and immediately saving.
	 */
	public function testFormEventCorrelation_SimpleUpdate() {
		$this->checkCreateUpdate([], true);
	}

	/**
	 * Test updating an Event Correlation.
	 *
	 * @dataProvider getEventCorrelationData
	 */
	public function testFormEventCorrelation_Update($data) {
		$this->checkCreateUpdate($data, true);
	}

	/**
	 * Test cloning of an Event Correlation.
	 */
	public function testFormEventCorrelation_Clone() {
		$this->page->login()->open('zabbix.php?action=correlation.list')->waitUntilReady();
		$this->query('link:Event correlation for clone')->one()->click();
		$this->page->waitUntilReady();

		$this->page->query('button:Clone')->one()->click();

		$form = $this->page->query('id:correlation.edit')->one()->asForm();
		$values_before = $form->getValues();
		$form->fill(['Name' => 'Cloned correlation']);
		$form->submit();
		$this->page->waitUntilReady();

		$this->assertMessage(TEST_GOOD, 'Correlation added');

		// Assert both correlations exist in table.
		$conditions = [['Type' => 'Old event tag name', 'Tag' => 'clone tag']];

		foreach (['Cloned correlation', 'Event correlation for clone'] as $name) {
			$this->assertTableCorrelationRow([
				'fields' => ['Name' => $name, 'Description' => 'Test description clone'],
				'conditions' => $conditions
			]);
		}

		// Assert data in edit form.
		$this->query('link:Cloned correlation')->one()->click();
		$this->page->waitUntilReady();
		$form->invalidate();
		$values_before['Name'] = 'Cloned correlation';
		unset($values_before['Type of calculation']); // hidden field
		unset($values_before['']); // 6.4 and below uses an empty field for "Close new event" checkbox
		$form->checkValue($values_before);
		$this->assertConditionsTable($conditions, $form);

		// Assert 'Operations' checkboxes.
		foreach (['Close old events' => true, 'Close new events' => false] as $operation => $expected_state) {
			// Find checkbox by label.
			$checkbox = $form->query('xpath:.//label[text()='.CXPathHelper::escapeQuotes($operation).
					']/parent::*//input[@type="checkbox"]'
			)->asCheckbox()->one();
			$this->assertEquals($expected_state, $checkbox->isChecked());
		}

		$this->assertEquals(true, $form->getField('Enabled')->getValue());
	}

	/**
	 * Test deletion of an Event Correlation.
	 */
	public function testFormEventCorrelation_Delete() {
		$this->page->login()->open('zabbix.php?action=correlation.list')->waitUntilReady();
		$table = $this->query('class:list-table')->asTable()->one();
		$row_count_before = $table->getRows()->count();

		$name = 'Event correlation for delete';
		$table->query('link', $name)->one()->click();
		$this->page->waitUntilReady();
		$this->page->query('button:Delete')->waitUntilClickable()->one()->click();
		$this->page->acceptAlert();
		$this->page->waitUntilReady();

		$this->assertMessage(TEST_GOOD, 'Correlation deleted');
		$this->assertTableStats($row_count_before - 1);
		$this->assertFalse($this->query('link', $name)->exists());
		$this->assertEquals(0, CDBHelper::getCount('SELECT NULL FROM correlation WHERE name='.CDBHelper::escape($name)));
	}

	/**
	 * Test opening an Event Correlation create form but then cancelling.
	 */
	public function testFormEventCorrelation_CancelCreate() {
		$this->checkCancelAction('create');
	}

	/**
	 * Test opening an Event Correlation update form but then cancelling.
	 */
	public function testFormEventCorrelation_CancelUpdate() {
		$this->checkCancelAction('update');
	}

	/**
	 * Test trying to add a Condition, but then cancelling.
	 */
	public function testFormEventCorrelation_CancelAddCondition() {
		$this->checkCancelAction('add_condition');
	}

	/**
	 * Test opening an Event Correlation clone form but then cancelling.
	 */
	public function testFormEventCorrelation_CancelClone() {
		$this->checkCancelAction('clone');
	}

	/**
	 * Test trying to delete an Event Correlation but then cancelling.
	 */
	public function testFormEventCorrelation_CancelDelete() {
		$this->checkCancelAction('delete');
	}

	/**
	 * Performs a creation or an update of an Event correlation.
	 *
	 * @param array $data      data from data provider
	 * @param bool  $update    if an update should be performed
	 */
	protected function checkCreateUpdate($data, $update = false) {
		if ($update) {
			// Dynamically generate test data for each update scenario, this is faster than restoring from a backup each time.
			$unique_name = 'Event correlation '.md5(serialize($data));
			CDataHelper::call('correlation.create', [
				[
					'name' => $unique_name,
					'description' => 'Test description update',
					'filter' => [
						'evaltype' => CONDITION_EVAL_TYPE_AND_OR,
						'conditions' => [['type' => ZBX_CORR_CONDITION_OLD_EVENT_TAG, 'tag' => '0 update tag']]
					],
					'operations' => [['type' => ZBX_CORR_OPERATION_CLOSE_OLD]]
				]
			]);
		}

		// Setup for DB data check later.
		$hash_before = CDBHelper::getHash(self::HASH_SQL);
		$count_sql = 'SELECT NULL FROM correlation';
		$count_before = CDBHelper::getCount($count_sql);

		// Open and fill Correlation data.
		$this->page->login()->open('zabbix.php?action=correlation.list')->waitUntilReady();

		if ($update) {
			$this->query('link', $unique_name)->one()->click();
		}
		else {
			$this->query('button:Create correlation')->one()->click();
		}

		$this->page->waitUntilReady();
		$form = $this->page->query('id:correlation.edit')->one()->asForm();

		// Set the default values and fill the form.
		if (array_key_exists('fields', $data)) {
			// Set the default expected operations.
			if (!array_key_exists('Close old events', $data['fields'])
					&& !array_key_exists('Close new events', $data['fields'])) {
				$data['fields']['Close old events'] = true;
				$data['fields']['Close new events'] = false;
			}

			// Special cases when updating.
			if ($update) {
				// When it is needed to avoid Name conflicts.
				if ($update && CTestArrayHelper::get($data, 'unique_name')) {
					$data['fields']['Name'] = $data['fields']['Name'].' update';
				}

				// Clear the Name field when updating (unless required).
				if (!CTestArrayHelper::get($data, 'update_name', false)) {
					unset($data['fields']['Name']);
				}
			}

			$form->fill($data['fields']);
		}

		// Remove the default condition when needed.
		if ($update && CTestArrayHelper::get($data, 'remove_condition')) {
			$form->query('button:Remove')->one()->click();
		}

		// Fill Condition data.
		$add_button = $this->page->query('id:condition_table')->one()->query('button:Add')->one();

		foreach (CTestArrayHelper::get($data, 'conditions', []) as $condition) {
			$add_button->click();
			$condition_dialog = COverlayDialogElement::find()->waitUntilReady()->all()->last();
			$condition_form = $condition_dialog->query('id:popup.condition')->asForm()->one();
			$this->fillConditionForm($condition_form, $condition);
			$condition_form->submit();

			// Only expect Condition modal to close if error not expected.
			if (!array_key_exists('condition_error', $data)) {
				$condition_dialog->waitUntilNotVisible();
			}
		}

		// Fill the 'Type of calculation' field and check the shown expression.
		if (array_key_exists('conditions', $data) && count($data['conditions']) > 1) {
			if (array_key_exists('calculation', $data)) {
				$form->getField('id:evaltype')->fill($data['calculation']);

				if ($data['calculation'] === 'Custom expression') {
					$form->query('id:formula')->waitUntilPresent()->one()->fill($data['formula']);
				}
			}

			// Only check the expression if 'Type of calculation' not set to 'Custom expression'.
			if (CTestArrayHelper::get($data, 'calculation') !== 'Custom expression') {
				$expression_text = $form->query('id:condition_label')->waitUntilPresent()->one()->getText();

				if (array_key_exists('expected_expression'.($update ? '_update' : ''), $data)) {
					$this->assertEquals($data['expected_expression'.($update ? '_update' : '')], $expression_text);
				}
				else {
					$this->assertEquals('A and B', $expression_text);
				}
			}
		}

		// Submit 'New event correlation' form only if error in the 'New condition' modal not expected.
		if (!array_key_exists('condition_error', $data)) {
			$form->submit();
		}

		// Assert result.
		if (CTestArrayHelper::get($data, 'expected') !== TEST_BAD) {
			// When no error expected.

			$this->page->waitUntilReady();
			$this->assertMessage(TEST_GOOD, 'Correlation '.($update ? 'updated' : 'added'));

			if ($update) {
				// Validate the old Name when updating.
				if (!CTestArrayHelper::get($data, 'update_name', false)) {
					$data['fields']['Name'] = $unique_name;
				}

				// Check the default condition when updating.
				if (!CTestArrayHelper::get($data, 'remove_condition')) {
					$data['conditions'] = array_merge([['Type' => 'Old event tag name', 'Tag' => '0 update tag']],
							CTestArrayHelper::get($data, 'conditions', [])
					);
				}
			}

			// Assert the data in list table.
			$this->assertTableCorrelationRow($data);

			// Assert data in DB.
			$this->assertEquals($count_before + ($update ? 0 : 1), CDBHelper::getCount($count_sql));
			$this->assertTrue(in_array($data['fields']['Name'], CDBHelper::getColumn('SELECT name FROM correlation', 'name')));

			// Simple update scenario - check that data in DB has not changed.
			if ($update &&  $data === []) {
				$this->assertEquals($hash_before, CDBHelper::getHash(self::HASH_SQL));
			}

			// Reload the page and open the form again to assert data.
			$this->page->open('zabbix.php?action=correlation.list')->waitUntilReady();
			$this->query('link', $data['fields']['Name'])->one()->click();
			$this->page->waitUntilReady();
			$form->invalidate();

			// Set the default expected 'Description' when updating.
			if ($update) {
				$data['fields']['Description'] = CTestArrayHelper::get($data['fields'], 'Description',
						'Test description update'
				);
			}

			// Set the expected 'Enabled' value.
			$data['fields']['Enabled'] = CTestArrayHelper::get($data['fields'], 'Enabled', true);

			// Check values in the form.
			$form->checkValue($data['fields']);

			// Assert Conditions table data.
			$this->assertConditionsTable($data['conditions'], $form, CTestArrayHelper::get($data, 'custom_conditions_order'));
		}
		else if (array_key_exists('condition_error', $data)) {
			// When expecting an error in the 'New condition' modal.

			$this->assertMessage(TEST_BAD, null, $data['condition_error']);
			$this->assertEquals($hash_before, CDBHelper::getHash(self::HASH_SQL));

			// Close the condition dialog.
			$condition_dialog->close();
		}
		else {
			// When expecting an error in the base form.

			if ($update) {
				$this->assertMessage(TEST_BAD, 'Cannot update correlation', $data['errors']);
			}
			else {
				// On 6.4 and below it sometimes shows 'update' instead of 'add' in the error message.
				$message = CMessageElement::find()->waitUntilVisible()->one();
				$this->assertContains($message->getTitle(), ['Cannot add correlation', 'Cannot update correlation']);

				foreach ($data['errors'] as $error) {
					$this->assertTrue($message->hasLine($error), 'Line "'.$error.'" was not found in message details');
				}
			}
			$this->assertEquals($hash_before, CDBHelper::getHash(self::HASH_SQL));
		}
	}

	/**
	 * Check the cancellation scenario for different possible actions.
	 *
	 * @param string $action    name of the action cancelled
	 */
	protected function checkCancelAction($action) {
		$old_hash = CDBHelper::getHash(self::HASH_SQL);

		$this->page->login()->open('zabbix.php?action=correlation.list')->waitUntilReady();
		$button_selector = ($action === 'create')
			? 'button:Create correlation'
			: 'link:Event correlation for cancel';
		$this->query($button_selector)->one()->click();
		$this->page->waitUntilReady();
		$form = $this->page->query('id:correlation.edit')->one()->asForm();

		// Press the Clone button in the clone scenario.
		if ($action === 'clone') {
			$form->query('button:Clone')->one()->click();
		}

		// Change values in the form.
		$form->fill(
			[
				'Name' => 'Test cancellation',
				'Description' => 'Test cancellation description',
				'Close old events' => false,
				'Close new events' => true,
				'Enabled' => true
			]
		);

		// Perform action specific steps.
		switch ($action) {
			case 'update':
			case 'clone':
				// Remove a Condition.
				$form->query('button:Remove')->one()->click();
				break;

			case 'delete':
				// Cancel deletion.
				$form->query('button:Delete')->one()->click();
				$this->page->dismissAlert();
				break;

			case 'add_condition':
				// Cancel adding a condition.
				$form->getField('Conditions')->query('button:Add')->one()->click();
				$condition_dialog = COverlayDialogElement::find()->waitUntilReady()->all()->last();
				$condition_form = $condition_dialog->query('id:popup.condition')->asForm()->one();
				$this->fillConditionForm($condition_form, ['Type' => 'New event tag name', 'Tag' => 'Cancelled tag']);
				$condition_dialog->query('button:Cancel')->one()->click();
				$condition_dialog->waitUntilNotVisible();
				break;
		}

		$form->query('button:Cancel')->waitUntilClickable()->one()->click();

		// Assert that an Event Correlation is not added in the UI.
		$this->assertFalse($this->query('link:Test cancellation')->exists());

		// Assert that the original Event Correlation was not updated in the UI.
		if (in_array($action, ['update', 'clone', 'delete'])) {
			$this->assertTableCorrelationRow([
				'fields' => ['Name' => 'Event correlation for cancel', 'Enabled' => false],
				'conditions' => [
					['Type' => 'Old event tag name', 'Tag' => 'cancel tag'],
					['Type' => 'New event tag name', 'Tag' => 'cancel tag']
				]
			]);
		}

		// Assert that nothing has changed in the DB.
		$this->assertEquals($old_hash, CDBHelper::getHash(self::HASH_SQL));
	}

	/**
	 * Asserts that the data table contains a row with the expected data from data provider.
	 *
	 * @param array $data    data from data provider
	 */
	protected function assertTableCorrelationRow($data) {
		$row = $this->query('class:list-table')->asTable()->one()->findRow('Name', $data['fields']['Name']);

		$expected_row_data = [
			'Operations' => $this->getExpectedOperationsText($data['fields']),
			'Status' => CTestArrayHelper::get($data['fields'], 'Enabled', true) ? 'Enabled' : 'Disabled'
		];

		// Assert the displayed table row data.
		$row->assertValues($expected_row_data);

		// Assert the Conditions column.
		$expected_conditions = $this->getExpectedConditionsArray($data['conditions'],
				CTestArrayHelper::get($data, 'custom_conditions_order')
		);

		// Get the actually displayed conditions and compare to the expected.
		$displayed_conditions = preg_split("/\r\n|\n|\r/", $row->getColumn('Conditions')->getText());

		// Ignore the order of conditions. The logic is not predetermined on 6.4 and lower.
		$expected_conditions = sort($expected_conditions);
		$displayed_conditions = sort($displayed_conditions);
		$this->assertEquals($expected_conditions, $displayed_conditions);
	}

	/**
	 * Asserts the Conditions table data in the create/edit form.
	 *
	 * @param array           $conditions      condition data from data provider
	 * @param CFormElement    $form            form element that contains the table	 *
	 * @param bool            $custom_order    if true then displayed conditions will be sorted
	 */
	protected function assertConditionsTable($conditions, $form, $custom_order = false) {
		$expected_conditions = $this->getExpectedConditionsArray($conditions, $custom_order);

		// On 6.0 the 'Add' button is placed inside a normal table row.
		$rows = $form->query('id:condition_table')->asTable()->one()->getRows();
		for ($i = 0; $i < $rows->count() - 1; $i++) {
			// Assert the 'Label' column - chr(65) = 'A', 66 = 'B', 67 = 'C', etc.
			$this->assertEquals(chr(65 + $i), $rows->get($i)->getColumn('Label')->getText());

			// Ignore the order of conditions. The logic is not predetermined on 6.4 and lower.
			$condition = $rows->get($i)->getColumn('Name')->getText();
			$this->assertTrue(in_array($condition, $expected_conditions));
			unset($expected_conditions[array_search($condition, $expected_conditions)]);
		}
	}

	/**
	 * Calculates the expected values of Conditions as displayed in the UI.
	 *
	 * @param array $conditions      conditions for this entry
	 * @param bool  $custom_order    if true then displayed conditions will be sorted
	 *
	 * @return array
	 */
	protected function getExpectedConditionsArray($conditions, $custom_order = false) {
		$result = [];

		// Sort by custom order if applicable.
		if ($custom_order) {
			// Add the custom_order parameter if missing (update scenario).
			foreach ($conditions as $i => $condition) {
				if (!array_key_exists('custom_order', $condition)) {
					$conditions[$i]['custom_order'] = 0;
				}
			}

			// The sorting itself.
			usort($conditions, function ($a, $b) {
				return $a['custom_order'] <=> $b['custom_order'];
			});
		}

		// Add each condition string to the result array.
		foreach ($conditions as $condition) {
			switch ($condition['Type']) {
				case 'Event tag pair':
					$text = 'Value of old event tag '.$condition['Old tag name'].' equals value of new event tag '.
							$condition['New tag name'];
					break;

				case 'Old event tag value':
					$text = 'Value of old event tag '.$condition['Tag'].' '.
							CTestArrayHelper::get($condition, 'Operator', 'equals').' '.$condition['Value'];
					break;

				case 'New event tag value':
					$text = 'Value of new event tag '.$condition['Tag'].' '.
							CTestArrayHelper::get($condition, 'Operator', 'equals').' '.$condition['Value'];
					break;

				default:
					$text = $condition['Type'].' '.
							(array_key_exists('Operator', $condition) ? $condition['Operator'] : 'equals').' '.
							CTestArrayHelper::get($condition, 'Tag', CTestArrayHelper::get($condition, 'Host groups'));
			}

			$result[] = trim($text);
		}

		return $result;
	}

	/**
	 * Calculates the expected value of Operations column as displayed in the UI.
	 *
	 * @param array $fields    entry fields
	 *
	 * @return string
	 */

	protected function getExpectedOperationsText($fields) {
		$fields = ($fields === null) ? [] : $fields;

		// 'Close old events' is used as the default.
		if (!array_key_exists('Close old events', $fields) && !array_key_exists('Close new events', $fields)) {
			$fields['Close old events'] = true;
		}

		$result = '';

		foreach (['Close old events', 'Close new events'] as $operation) {
			if (CTestArrayHelper::get($fields, $operation)) {
				// On 6.0 the "Close new events" field shows as "Close new event" in the table instead.
				$result .= ($operation === 'Close new events' ? "\nClose new event" : "\nClose old events");
			}
		}

		// Trim the leading newline character.
		return trim($result);
	}

	/**
	 * Fills the Correlation condition form and waits for reload when filling the type dropdown.
	 *
	 * @param CFormElement $form      form element that will be filled
	 * @param array        $values    the values to fill in the form
	 */
	protected function fillConditionForm($form, $values) {
		$values['Type'] = CFormElement::RELOADABLE_FILL($values['Type']);
		$values['Operator'] = CTestArrayHelper::get($values, 'Operator', 'equals');
		unset($values['custom_order']);
		$form->fill($values);
	}
}
