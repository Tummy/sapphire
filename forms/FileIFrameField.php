<?php 
/**
 * A field that allows you to attach a file to a DataObject without submitting the form it is part of, through the use
 * of an iframe.
 *
 * If all you need is a simple file upload, it is reccomended you use {@link FileField}
 *
 * @package forms
 * @subpackage fields-files
 */
class FileIFrameField extends FileField {
	
	protected $template = 'FileIFrameField';
	
	public static $allowed_actions = array (
		'iframe',
		'EditFileForm',
		'DeleteFileForm'
	);
	
	/**
	 * Flag that controls whether or not new files
	 * can be uploaded by the user from their local computer.
	 * 
	 * @var boolean
	 */
	protected $canUploadNewFile = true;	
	
	/** 
	 * Sets whether or not files can be uploaded into the CMS from the user's local computer 
	 * 
	 * @param boolean
	 */
	function setCanUploadNewFile($can) {
		$this->canUploadNewFile = $can;
	}
	
	/**
	 * @return boolean
	 */
	function getCanUploadNewFile() {
		return $this->canUploadNewFile;
	}
	
	/**
	 * The data class that this field is editing.
	 * @return string Class name
	 */
	public function dataClass() {
		if($this->form && $this->form->getRecord()) {
			$class = $this->form->getRecord()->has_one($this->getName());
			return ($class) ? $class : 'File';
		} else {
			return 'File';
		}
	}
	
	/**
	 * @return string
	 */
	public function Field() {
		Requirements::css(SAPPHIRE_DIR . '/thirdparty/jquery-ui-themes/smoothness/jquery-ui.css');
		Requirements::add_i18n_javascript(SAPPHIRE_DIR . '/javascript/lang');
		Requirements::javascript(SAPPHIRE_DIR . '/thirdparty/jquery/jquery.js');
		Requirements::javascript(SAPPHIRE_DIR . '/thirdparty/jquery-ui/jquery-ui.js');
		
		
		if($this->form->getRecord() && $this->form->getRecord()->exists()) {
			$record = $this->form->getRecord();
			if(class_exists('Translatable') && Object::has_extension('SiteTree', 'Translatable') && $record->Locale){
				$iframe = "iframe?locale=".$record->Locale;
			}else{
				$iframe = "iframe";
			}
			
			return $this->createTag (
				'iframe',
				array (
					'name'  => $this->getName() . '_iframe',
					'src'   => Controller::join_links($this->Link(), $iframe),
					'style' => 'height: 152px; width: 100%; border: none;'
				)
			) . $this->createTag (
				'input',
				array (
					'type'  => 'hidden',
					'id'    => $this->ID(),
					'name'  => $this->getName() . 'ID',
					'value' => $this->attrValue()
				)
			);
		}
		
		$this->setValue(sprintf(_t (
			'FileIFrameField.ATTACHONCESAVED', '%ss can be attached once you have saved the record for the first time.'
		), $this->FileTypeName()));
		
		return FormField::field();
	}
	
	/**
	 * Attempt to retreive a File object that has already been attached to this forms data record
	 *
	 * @return File|null
	 */
	public function AttachedFile() {
		return $this->form->getRecord() ? $this->form->getRecord()->{$this->getName()}() : null;
	}
	
	/**
	 * @return string
	 */
	public function iframe() {
		// clear the requirements added by any parent controllers
		Requirements::clear();
		Requirements::add_i18n_javascript('sapphire/javascript/lang');
		Requirements::javascript(SAPPHIRE_DIR . '/thirdparty/jquery/jquery.js');
		Requirements::javascript('sapphire/javascript/FileIFrameField.js');
		
		Requirements::css('sapphire/css/FileIFrameField.css');
		
		return $this->renderWith($this->template);
	}
	
	/**
	 * @return Form
	 */
	public function EditFileForm() {
		$uploadFile = _t('FileIFrameField.FROMCOMPUTER', 'From your Computer');
		$selectFile = _t('FileIFrameField.FROMFILESTORE', 'From the File Store');
		
		if($this->AttachedFile() && $this->AttachedFile()->ID) {
			$title = sprintf(_t('FileIFrameField.REPLACE', 'Replace %s'), $this->FileTypeName());
		} else {
			$title = sprintf(_t('FileIFrameField.ATTACH', 'Attach %s'), $this->FileTypeName());
		}
		
		$fileSources = array();
		
		if(singleton($this->dataClass())->canCreate()) {
			if($this->canUploadNewFile) {
				$fileSources["new//$uploadFile"] = new FileField('Upload', '');
			}
		}
		
		$fileSources["existing//$selectFile"] = new TreeDropdownField('ExistingFile', '', 'File');

		$fields = new FieldList (
			new HeaderField('EditFileHeader', $title),
			new SelectionGroup('FileSource', $fileSources)
		);
		
		// locale needs to be passed through from the iframe source
		if(isset($_GET['locale'])) {
			$fields->push(new HiddenField('locale', '', $_GET['locale']));
		}
		
		return new Form (
			$this,
			'EditFileForm',
			$fields,
			new FieldList(
				new FormAction('save', $title)
			)
		);
	}
	
	public function save($data, $form) {
		// check the user has entered all the required information
		if (
			!isset($data['FileSource'])
			|| ($data['FileSource'] == 'new' && (!isset($_FILES['Upload']) || !$_FILES['Upload']))
			|| ($data['FileSource'] == 'existing' && (!isset($data['ExistingFile']) || !$data['ExistingFile']))
		) {
			$form->sessionMessage(_t('FileIFrameField.NOSOURCE', 'Please select a source file to attach'), 'required');
			Director::redirectBack();
			return;
		}
		
		$desiredClass = $this->dataClass();
		
		// upload a new file
		if($data['FileSource'] == 'new') {
			$fileObject = Object::create($desiredClass);
			
			try {
				$this->upload->loadIntoFile($_FILES['Upload'], $fileObject, $this->folderName);
			} catch (Exception $e){
				$form->sessionMessage(_t('FileIFrameField.DISALLOWEDFILETYPE', 'This filetype is not allowed to be uploaded'), 'bad');
				Director::redirectBack();
				return;
			}
			
			if($this->upload->isError()) {
				Director::redirectBack();
				return;
			}
			
			$this->form->getRecord()->{$this->getName() . 'ID'} = $fileObject->ID;
			
			$fileObject->OwnerID = (Member::currentUser() ? Member::currentUser()->ID : 0);
			$fileObject->write();
		}
		
		// attach an existing file from the assets store
		if($data['FileSource'] == 'existing') {
			$fileObject = DataObject::get_by_id('File', $data['ExistingFile']);
			
			// dont allow the user to attach a folder by default
			if(!$fileObject || ($fileObject instanceof Folder && $desiredClass != 'Folder')) {
				Director::redirectBack();
				return;
			}
			
			$this->form->getRecord()->{$this->getName() . 'ID'} = $fileObject->ID;
			
			if(!$fileObject instanceof $desiredClass) {
				$fileObject->ClassName = $desiredClass;
				$fileObject->write();
			}
		}
		
		$this->form->getRecord()->write();
		Director::redirectBack();
	}
	
	/**
	 * @return Form
	 */
	public function DeleteFileForm() {
		$form = new Form (
			$this,
			'DeleteFileForm',
			new FieldList (
				new HiddenField('DeleteFile', null, false)
			),
			new FieldList (
				$deleteButton = new FormAction (
					'delete', sprintf(_t('FileIFrameField.DELETE', 'Delete %s'), $this->FileTypeName())
				)
			)
		);
		
		$deleteButton->addExtraClass('delete');
		return $form;
	}
	
	public function delete($data, $form) {
		// delete the actual file, or just un-attach it?
		if(isset($data['DeleteFile']) && $data['DeleteFile']) {
			$file = DataObject::get_by_id('File', $this->form->getRecord()->{$this->getName() . 'ID'});
			
			if($file) {
				$file->delete();
			}
		}
		
		// then un-attach file from this record
		$this->form->getRecord()->{$this->getName() . 'ID'} = 0;
		$this->form->getRecord()->write();
		
		Director::redirectBack();
	}
	
	/**
	 * Get the type of file this field is used to attach (e.g. File, Image)
	 *
	 * @return string
	 */
	public function FileTypeName() {
		return _t('FileIFrameField.FILE', 'File');
	}
	
}
