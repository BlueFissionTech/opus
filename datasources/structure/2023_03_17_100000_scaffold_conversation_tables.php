<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\Structure\MySQLStructure as Structure;
use BlueFission\Data\Storage\Structure\MySQLScaffold as Scaffold;

class ScaffoldConversationTables extends Delta
{
	public function change() {
		Scaffold::create('languages', function( Structure $entity ) {
			$entity->incrementer('language_id');
			$entity->text('name');
			$entity->text('label')->null();
			$entity->timestamps();
			$entity->comment("Human languages registered by the system.");
		});
		
		Scaffold::create('topics', function( Structure $entity ) {
			$entity->incrementer('topic_id');
			$entity->text('name');
			$entity->text('label');
			$entity->numeric('weight');
			$entity->timestamps();
			$entity->comment("Distinct bodies of concern and circumstantial facts around which attention and dialogue are framed.");
		});

		Scaffold::create('topic_routes', function( Structure $entity) {
			$entity->incrementer('topic_route_id');
			$entity->numeric('from');
			$entity->numeric('to');
			$entity->numeric('weight');
			$entity->timestamps();
			$entity->comment("The expected routes for different types of topic.");
		});
		
		Scaffold::create('dialogue_types', function( Structure $entity ) {
			$entity->incrementer('dialogue_type_id');
			$entity->text('name');
			$entity->text('label')->null();
			$entity->timestamps();
			$entity->comment("Categories of dialogue including statements, queries, and responses.");
		});

		Scaffold::create('dialogues', function( Structure $entity ) {
			$entity->incrementer('dialogue_id');
			$entity->numeric('dialogue_type_id')
				->foreign('dialogue_types', 'dialogue_type_id');
			$entity->numeric('language_id')
				->foreign('languages', 'language_id');
			$entity->numeric('topic_id')
				->foreign('topics', 'topic_id');
			$entity->text('text', 1028);
			$entity->text('tokenized', 1028)->null();
			$entity->numeric('weight');
			$entity->timestamps();
			$entity->comment("Modeled conversational segements.");
		});

		Scaffold::create('conversations', function( Structure $entity ) {
			$entity->incrementer('conversation_id');
			$entity->comment("Individual conversations.");
		});

		Scaffold::create('messages', function( Structure $entity ){
			$entity->incrementer('message_id');
			$entity->numeric('conversation_id')
				->foreign('conversations', 'conversation_id');
			$entity->numeric('user_id');
			$entity->numeric('topic_id');
			$entity->numeric('communication_id')
				->foreign('communications', 'communication_id');
			$entity->comment("Messages within a conversation.");
		});
		
		Scaffold::create('tags', function( Structure $entity ) {
			$entity->incrementer('tag_id');
			$entity->text('label');
			$entity->timestamps();
			$entity->comment("Words and phrases tagged to objects.");
		});

		Scaffold::create('topic_to_tags', function( Structure $entity ) {
			$entity->incrementer('topic_to_tag_id');
			$entity->numeric('topic_id');
			$entity->numeric('tag_id');
			$entity->numeric('weight');
			// $entity->timestamps();
			$entity->comment("Word and idea based synomym relationships for entities.");
		});
		
		Scaffold::create('entity_types', function( Structure $entity ) {
			$entity->incrementer('entity_type_id');
			$entity->text('name');
			$entity->text('label')->null();
			$entity->timestamps();
			$entity->comment("The series of ideas as words and their definitions.");
		});

		Scaffold::create('entities', function( Structure $entity ) {
			$entity->incrementer('entity_id');
			$entity->text('name');
			$entity->text('label')->null();
			$entity->timestamps();
			$entity->comment("The series of ideas as words and their definitions.");
		});

		Scaffold::create('entity_to_entity_types', function( Structure $entity ) {
			$entity->incrementer('entity_to_entity_type_id');
			$entity->numeric('entity_id');
			$entity->numeric('entity_type_id');
			$entity->numeric('weight');
			$entity->comment("tokenizable classifications of entities.");
		});

		Scaffold::create('entity_to_tags', function( Structure $entity ) {
			$entity->incrementer('entity_to_tag_id');
			$entity->numeric('entity_id');
			$entity->numeric('tag_id');
			$entity->numeric('weight');
			$entity->comment("Word and idea based synomym relationships for entities.");
		});

		Scaffold::create('verbs', function( Structure $entity ) {
			$entity->incrementer('verb_id');
			$entity->text('name');
			$entity->text('label')->null();
			$entity->timestamps();
			$entity->comment("The actions an entity is capable of doing");
		});

		Scaffold::create('definitions', function( Structure $entity ) {
			$entity->incrementer('definition_id');
			$entity->numeric('entity_id');
			$entity->text('property', 255);
			$entity->numeric('verb_id')
				->foreign('verbs', 'verb_id');
			$entity->text('value')->null();
			$entity->comment("The individual detailed properties of a general entity class");
		});

		Scaffold::create('fact_types', function ( Structure $entity) {
			$entity->incrementer('fact_type_id');
			$entity->text('name');
			$entity->text('label')->null();
			$entity->timestamps();
			$entity->comment("A relationship between a fact's object and its value.");
		});

		Scaffold::create('facts', function( Structure $entity ) {
			$entity->incrementer('fact_id');
			$entity->numeric('fact_type_id');
			$entity->text('is_negated')->null();
			$entity->text('var');
			$entity->text('value');
			$entity->text('privilege');
			$entity->text('ttl');
			$entity->timestamps();
			$entity->comment("A collection of defined facts and ground truths for the story.");
		});
	}

	public function revert() {
		Scaffold::delete('facts');
		Scaffold::delete('fact_types');
		Scaffold::delete('definitions');
		Scaffold::delete('verbs');
		Scaffold::delete('entity_to_tags');
		Scaffold::delete('entity_to_entity_types');
		Scaffold::delete('entities');
		Scaffold::delete('entity_types');
		Scaffold::delete('topic_to_tags');
		Scaffold::delete('tags');
		Scaffold::delete('messages');
		Scaffold::delete('conversations');
		Scaffold::delete('dialogues');
		Scaffold::delete('dialogue_types');
		Scaffold::delete('topic_routes');
		Scaffold::delete('topics');
		Scaffold::delete('languages');
	}
}