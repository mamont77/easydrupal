import { Plugin } from 'ckeditor5/src/core';
import { LabeledFieldView, createLabeledInputText } from 'ckeditor5/src/ui';
import { additionalFormElements} from './utils';

export default class EditorAdvancedLinkUi extends Plugin {
  init() {
    // TRICKY: Work-around until the CKEditor team offers a better solution:
    // force the ContextualBalloon to get instantiated early thanks to DrupalImage not yet being optimized like
    // https://github.com/ckeditor/ckeditor5/commit/c276c45a934e4ad7c2a8ccd0bd9a01f6442d4cd3#diff-1753317a1a0b947ca8b66581b533616a5309f6d4236a527b9d21ba03e13a78d8.
    this.editor.plugins.get('LinkUI')._createViews();
    this._changeFormToVertical();
    this._addExtraFormFields();
  }

  _addExtraFormFields() {
    const editor = this.editor;
    const enabledModelNames = editor.plugins.get('EditorAdvancedLinkEditing').enabledModelNames;
    // Copy the same solution from LinkUI as pointed out on
    // https://www.drupal.org/project/drupal/issues/3317769#comment-14985648 and
    // https://git.drupalcode.org/project/drupal/-/merge_requests/2909/diffs?commit_id=cc2cece3be1a9513b02a53d8a6862a6841ef4d5a.
    editor.plugins.get('ContextualBalloon')
      .on('set:visibleView', (evt, propertyName, newValue, oldValue) => {
        if (newValue === oldValue) {
          return;
        }
        enabledModelNames.reverse().forEach((modelName) => {
          this._createExtraFormField(modelName, additionalFormElements[modelName]);
        });
        this._handleExtraFormFieldSubmit(enabledModelNames);
      });
  }

  _changeFormToVertical() {
    const linkFormView = this.editor.plugins.get( 'LinkUI' ).formView;
    linkFormView.extendTemplate( {
      attributes: {
        class: [ 'ck-vertical-form', 'ck-link-form_layout-vertical' ]
      }
    } );
  }

  _createExtraFormField(modelName, options) {
    const editor = this.editor;
    const locale = editor.locale;
    const linkFormView = editor.plugins.get( 'LinkUI' ).formView;
    const linkCommand = editor.commands.get( 'link' );
    if (typeof linkFormView[modelName] == 'undefined') {
      const extraFieldView = new LabeledFieldView(locale, createLabeledInputText);
      extraFieldView.label = options.label;
      linkFormView.children.add(extraFieldView, 1);

      linkFormView.on('render', () => {
        linkFormView._focusables.add(extraFieldView, 1);
        linkFormView.focusTracker.add(extraFieldView.element);
      });

      linkFormView[modelName] = extraFieldView;
      linkFormView[modelName].fieldView.bind( 'value' ).to( linkCommand, modelName );
      // Note: Copy & pasted from LinkUI.
      // https://github.com/ckeditor/ckeditor5/blob/f0a093339631b774b2d3422e2a579e27be79bbeb/packages/ckeditor5-link/src/linkui.js#L333-L333
      linkFormView[modelName].fieldView.element.value = linkCommand[modelName] || '';
    }
  }

  _handleExtraFormFieldSubmit(modelNames) {
    const editor = this.editor;
    const linkFormView = editor.plugins.get( 'LinkUI' ).formView;
    const linkCommand = editor.commands.get( 'link' );

    this.listenTo( linkFormView, 'submit', () => {
      const values = modelNames.reduce((state, modelName) => {
        state[modelName] = linkFormView[modelName].fieldView.element.value;
        return state;
      }, {});
      // Stop the execution of the link command caused by closing the form.
      // Inject the extra attribute value. The highest priority listener here
      // injects the argument (here below 👇).
      // - The high priority listener in
      //   _addExtraAttributeOnLinkCommandExecute() gets that argument and sets
      //   the extra attribute.
      // - The normal (default) priority listener in ckeditor5-link sets
      //   (creates) the actual link.
      linkCommand.once( 'execute', ( evt, args ) => {
        if (args.length < 3) {
          args.push( values );
        } else if (args.length === 3) {
          Object.assign(args[2], values);
        } else {
          throw Error('The link command has more than 3 arguments.')
        }
      }, { priority: 'highest' } );
    }, { priority: 'high' } );
  }

}
