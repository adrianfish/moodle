YUI.add('moodle-atto_modelviewer-button', function (Y, NAME) {

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
 * @package    atto_modelviewer
 * @copyright  2013 Damyon Wiese  <damyon@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @module moodle-atto_modelviewer-button
 */

/**
 * Atto text editor modelviewer plugin.
 *
 * @namespace M.atto_modelviewer
 * @class button
 * @extends M.editor_atto.EditorPlugin
 */

var COMPONENTNAME = 'atto_modelviewer',
    TEMPLATES = {
        FORM: '' +
            '<form class="atto_form">' +
                '{{{library}}}' +
                '<label for="{{elementid}}_{{CSS.EQUATION_TEXT}}">{{{get_string "editequation" component texdocsurl}}}</label>' +
                '<textarea class="fullwidth {{CSS.EQUATION_TEXT}}" ' +
                        'id="{{elementid}}_{{CSS.EQUATION_TEXT}}" rows="8"></textarea><br/>' +
                '<label for="{{elementid}}_{{CSS.EQUATION_PREVIEW}}">{{get_string "preview" component}}</label>' +
                '<div describedby="{{elementid}}_cursorinfo" class="well well-small fullwidth {{CSS.EQUATION_PREVIEW}}" ' +
                        'id="{{elementid}}_{{CSS.EQUATION_PREVIEW}}"></div>' +
                '<div id="{{elementid}}_cursorinfo">{{get_string "cursorinfo" component}}</div>' +
                '<div class="mdl-align">' +
                    '<br/>' +
                    '<button class="{{CSS.SUBMIT}}">{{get_string "saveequation" component}}</button>' +
                '</div>' +
            '</form>',
        LIBRARY: '' +
            '<div class="{{CSS.LIBRARY}}">' +
                '<ul>' +
                    '{{#each library}}' +
                        '<li><a href="#{{../elementid}}_{{../CSS.LIBRARY_GROUP_PREFIX}}_{{@key}}">' +
                            '{{get_string groupname ../component}}' +
                        '</a></li>' +
                    '{{/each}}' +
                '</ul>' +
                '<div class="{{CSS.LIBRARY_GROUPS}}">' +
                    '{{#each library}}' +
                        '<div id="{{../elementid}}_{{../CSS.LIBRARY_GROUP_PREFIX}}_{{@key}}">' +
                            '<div role="toolbar">' +
                            '{{#split "\n" elements}}' +
                                '<button tabindex="-1" data-tex="{{this}}" aria-label="{{this}}" title="{{this}}">' +
                                    '{{../../DELIMITERS.START}}{{this}}{{../../DELIMITERS.END}}' +
                                '</button>' +
                            '{{/split}}' +
                            '</div>' +
                        '</div>' +
                    '{{/each}}' +
                '</div>' +
            '</div>'
    };

Y.namespace('M.atto_modelviewer').Button = Y.Base.create('button', Y.M.editor_atto.EditorPlugin, [], {
        initializer: function () {

            console.log("calling addButton ...");
            this.addButton({
                icon: 'e/bold',
                callback: this._displayDialogue
            });
            console.log("addButton called");
        },
        _displayDialogue: function () {

            console.log("HHHHHHH");

            this._currentSelection = this.get('host').getSelection();

            var dialogue = this.getDialogue({
                    headerContent: M.util.get_string('pluginname', COMPONENTNAME),
                    focusAfterHide: true,
                    width: 600//,
                    //focusOnShowSelector: SELECTORS.EQUATION_TEXT
                });

            var content = this._getDialogueContent();
            dialogue.set('bodyContent', content);
        },
        _getDialogueContent: function () {

            var library = this._getLibraryContent(),
                //throttledUpdate = this._throttle(this._updatePreview, 500),
                template = Y.Handlebars.compile(TEMPLATES.FORM);

            this._content = Y.Node.create(template({
                    elementid: this.get('host').get('elementid'),
                    component: COMPONENTNAME,
                    library: library
                }));
            return this._content;
         }
    });


}, '@VERSION@', {"requires": ["moodle-editor_atto-plugin"]});
