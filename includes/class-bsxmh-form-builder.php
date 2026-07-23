<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Form_Builder {
    private const TYPES = array( 'text','textarea','email','phone','number','url','date','select','radio','checkbox','multiselect','file','image','heading','html' );

    public static function register(): void {
        add_action( 'admin_post_bsxmh_save_form_field', array( __CLASS__, 'save_field' ) );
        add_action( 'admin_post_bsxmh_delete_form_field', array( __CLASS__, 'delete_field' ) );
        add_action( 'admin_post_bsxmh_reorder_form_fields', array( __CLASS__, 'reorder_fields' ) );
        add_action( 'admin_post_bsxmh_save_core_fields', array( __CLASS__, 'save_core_fields' ) );
    }

    public static function ensure_defaults(): void {
        global $wpdb;
        $table = BSXMH_DB::table( 'form_fields' );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count ) return;
        $now = current_time( 'mysql' );
        $defaults = array(
            array( 'address', 'Address', 'textarea', 0 ),
            array( 'occupation', 'Occupation', 'text', 10 ),
        );
        foreach ( $defaults as $d ) {
            $wpdb->insert( $table, array(
                'field_key'=>$d[0], 'label'=>$d[1], 'field_type'=>$d[2],
                'field_options'=>wp_json_encode(array('placeholder'=>'','help'=>'','default'=>'','options'=>array(),'max_size'=>2)),
                'validation_rules'=>wp_json_encode(array('min_length'=>0,'max_length'=>0,'min'=>'','max'=>'','unique'=>0)),
                'visibility'=>'registration', 'is_required'=>0, 'is_enabled'=>1, 'member_editable'=>1,
                'sort_order'=>$d[3], 'created_at'=>$now, 'updated_at'=>$now,
            ) );
        }
    }

    public static function fields( bool $enabled_only = true ): array {
        global $wpdb;
        $where = $enabled_only ? ' WHERE is_enabled=1' : '';
        return $wpdb->get_results( 'SELECT * FROM ' . BSXMH_DB::table('form_fields') . $where . ' ORDER BY sort_order ASC,id ASC' ) ?: array();
    }

    public static function get( int $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM '.BSXMH_DB::table('form_fields').' WHERE id=%d', $id ) ); }
    public static function options( $field ): array { $v=json_decode((string)$field->field_options,true); return is_array($v)?$v:array(); }
    public static function rules( $field ): array { $v=json_decode((string)$field->validation_rules,true); return is_array($v)?$v:array(); }
    public static function values( int $user_id ): array { $v=get_user_meta($user_id,'bsxmh_custom_fields',true); return is_array($v)?$v:array(); }

    public static function core_settings(): array { return wp_parse_args( get_option( 'bsxmh_form_core', array() ), array( 'phone_enabled'=>1, 'phone_required'=>0, 'phone_label'=>'Mobile Number' ) ); }
    public static function save_core_fields(): void {
        if(!current_user_can('bsxmh_manage_settings')) wp_die('Not allowed.'); check_admin_referer('bsxmh_save_core_fields');
        update_option('bsxmh_form_core',array('phone_enabled'=>empty($_POST['phone_enabled'])?0:1,'phone_required'=>empty($_POST['phone_required'])?0:1,'phone_label'=>sanitize_text_field(wp_unslash($_POST['phone_label']??'Mobile Number'))),false); self::back('saved');
    }

    public static function save_field(): void {
        if ( ! current_user_can('bsxmh_manage_settings') ) wp_die('Not allowed.');
        check_admin_referer('bsxmh_save_form_field');
        global $wpdb;
        $id=absint($_POST['field_id']??0);
        $label=sanitize_text_field(wp_unslash($_POST['label']??''));
        $key=sanitize_key(wp_unslash($_POST['field_key']??''));
        $type=sanitize_key(wp_unslash($_POST['field_type']??'text'));
        if(!$label || !$key || !in_array($type,self::TYPES,true)) self::back('invalid');
        $reserved=array('display_name','email','password','user_login','member_id','status');
        if(in_array($key,$reserved,true)) self::back('reserved');
        $exists=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.BSXMH_DB::table('form_fields').' WHERE field_key=%s AND id<>%d',$key,$id));
        if($exists) self::back('duplicate');
        $raw_options=preg_split('/\r\n|\r|\n/',(string)wp_unslash($_POST['choices']??''));
        $choices=array_values(array_filter(array_map('sanitize_text_field',$raw_options)));
        $data=array(
            'field_key'=>$key,'label'=>$label,'field_type'=>$type,
            'field_options'=>wp_json_encode(array(
                'placeholder'=>sanitize_text_field(wp_unslash($_POST['placeholder']??'')),
                'help'=>'html'===$type?wp_kses_post(wp_unslash($_POST['help']??'')):sanitize_textarea_field(wp_unslash($_POST['help']??'')),
                'default'=>sanitize_text_field(wp_unslash($_POST['default_value']??'')),
                'options'=>$choices,
                'max_size'=>max(1,min(20,absint($_POST['max_size']??2))),
            )),
            'validation_rules'=>wp_json_encode(array(
                'min_length'=>absint($_POST['min_length']??0),'max_length'=>absint($_POST['max_length']??0),
                'min'=>sanitize_text_field(wp_unslash($_POST['min_value']??'')),'max'=>sanitize_text_field(wp_unslash($_POST['max_value']??'')),
                'unique'=>empty($_POST['is_unique'])?0:1,
            )),
            'visibility'=>in_array($_POST['visibility']??'',array('registration','admin','public','hidden'),true)?sanitize_key($_POST['visibility']):'registration',
            'is_required'=>empty($_POST['is_required'])?0:1,'is_enabled'=>empty($_POST['is_enabled'])?0:1,
            'member_editable'=>empty($_POST['member_editable'])?0:1,'sort_order'=>intval($_POST['sort_order']??0),'updated_at'=>current_time('mysql'),
        );
        if($id){ $ok=$wpdb->update(BSXMH_DB::table('form_fields'),$data,array('id'=>$id)); }
        else { $data['created_at']=current_time('mysql'); $ok=$wpdb->insert(BSXMH_DB::table('form_fields'),$data); }
        self::back(false===$ok?'failed':'saved');
    }

    public static function delete_field(): void {
        if(!current_user_can('bsxmh_manage_settings')) wp_die('Not allowed.');
        $id=absint($_GET['field_id']??0); check_admin_referer('bsxmh_delete_form_field_'.$id);
        global $wpdb; $wpdb->delete(BSXMH_DB::table('form_fields'),array('id'=>$id),array('%d')); self::back('deleted');
    }
    public static function reorder_fields(): void {
        if(!current_user_can('bsxmh_manage_settings')) wp_die('Not allowed.');
        check_admin_referer('bsxmh_reorder_form_fields'); global $wpdb;
        foreach((array)($_POST['field_order']??array()) as $i=>$id) $wpdb->update(BSXMH_DB::table('form_fields'),array('sort_order'=>$i*10),array('id'=>absint($id)),array('%d'),array('%d'));
        self::back('reordered');
    }
    private static function back(string $status): void { wp_safe_redirect(add_query_arg('bsxmh_form_status',$status,admin_url('admin.php?page=bsxmh-form-builder'))); exit; }

    public static function render_admin(): void {
        $edit=self::get(absint($_GET['edit_field']??0)); $fields=self::fields(false); $o=$edit?self::options($edit):array(); $r=$edit?self::rules($edit):array();
        $status=sanitize_key($_GET['bsxmh_form_status']??'');
        echo '<div class="wrap bsxmh-wrap"><h1>Registration &amp; Profile Builder</h1>';
        $core=self::core_settings();
        echo '<div class="bsxmh-panel"><h2>Core Fields</h2><p>Full Name, Email and Password are protected login fields. You can configure the optional mobile field.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bsxmh_save_core_fields'); echo '<input type="hidden" name="action" value="bsxmh_save_core_fields"><p><label>Mobile Field Label</label> <input class="regular-text" name="phone_label" value="'.esc_attr($core['phone_label']).'"></p><label style="margin-right:18px"><input type="checkbox" name="phone_enabled" value="1" '.checked($core['phone_enabled'],1,false).'> Enabled</label><label><input type="checkbox" name="phone_required" value="1" '.checked($core['phone_required'],1,false).'> Required</label>'; submit_button('Save Core Fields','secondary'); echo '</form></div>';
        if($status) echo '<div class="notice '.(in_array($status,array('saved','deleted','reordered'),true)?'notice-success':'notice-error').' is-dismissible"><p>'.esc_html(ucwords(str_replace('_',' ',$status))).'</p></div>';
        echo '<div class="bsxmh-grid-2"><div class="bsxmh-panel"><h2>'.($edit?'Edit Field':'Add Field').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bsxmh_save_form_field'); echo '<input type="hidden" name="action" value="bsxmh_save_form_field"><input type="hidden" name="field_id" value="'.esc_attr($edit->id??0).'">';
        self::row('Label','label',$edit->label??'',true); self::row('Field Key','field_key',$edit->field_key??'',true,'lowercase_letters_numbers_underscores');
        echo '<p><label>Field Type</label><select name="field_type">'; foreach(self::TYPES as $t) echo '<option value="'.esc_attr($t).'" '.selected($edit->field_type??'text',$t,false).'>'.esc_html(ucwords(str_replace('_',' ',$t))).'</option>'; echo '</select></p>';
        self::row('Placeholder','placeholder',$o['placeholder']??''); self::row('Default Value','default_value',$o['default']??'');
        echo '<p><label>Help Text</label><textarea name="help" rows="2">'.esc_textarea($o['help']??'').'</textarea></p><p><label>Choices (one per line)</label><textarea name="choices" rows="5">'.esc_textarea(implode("\n",$o['options']??array())).'</textarea></p>';
        echo '<div class="bsxmh-grid-2">'; self::row('Minimum Length','min_length',$r['min_length']??0,false,'0'); self::row('Maximum Length','max_length',$r['max_length']??0,false,'0'); self::row('Minimum Value','min_value',$r['min']??''); self::row('Maximum Value','max_value',$r['max']??''); self::row('Upload Max MB','max_size',$o['max_size']??2); self::row('Sort Order','sort_order',$edit->sort_order??0); echo '</div>';
        echo '<p><label>Visibility</label><select name="visibility">'; foreach(array('registration'=>'Registration + Profile','admin'=>'Admin Only','public'=>'Public Profile','hidden'=>'Hidden') as $k=>$v) echo '<option value="'.$k.'" '.selected($edit->visibility??'registration',$k,false).'>'.$v.'</option>'; echo '</select></p>';
        foreach(array('is_required'=>'Required','is_enabled'=>'Enabled','member_editable'=>'Member Editable','is_unique'=>'Unique Value') as $k=>$v){$checked='is_unique'===$k?($r['unique']??0):($edit->$k??('is_enabled'===$k||'member_editable'===$k)); echo '<label style="margin-right:18px"><input type="checkbox" name="'.$k.'" value="1" '.checked($checked,1,false).'> '.$v.'</label>';}
        submit_button($edit?'Update Field':'Add Field'); echo '</form></div>';
        echo '<div class="bsxmh-panel"><h2>Fields</h2><p>Core fields—Full Name, Email and Password—remain protected. Drag rows to reorder custom fields.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bsxmh_reorder_form_fields'); echo '<input type="hidden" name="action" value="bsxmh_reorder_form_fields"><table class="widefat striped"><thead><tr><th>Order</th><th>Field</th><th>Type</th><th>Rules</th><th>Actions</th></tr></thead><tbody id="bsxmh-sort-fields">';
        foreach($fields as $f){echo '<tr><td class="bsxmh-drag">☰<input type="hidden" name="field_order[]" value="'.$f->id.'"></td><td><strong>'.esc_html($f->label).'</strong><br><code>'.esc_html($f->field_key).'</code></td><td>'.esc_html($f->field_type).'</td><td>'.($f->is_enabled?'Enabled':'Disabled').($f->is_required?' · Required':'').($f->member_editable?' · Editable':'').'</td><td><a href="'.esc_url(admin_url('admin.php?page=bsxmh-form-builder&edit_field='.$f->id)).'">Edit</a> | <a class="submitdelete" onclick="return confirm(\'Delete this field? Existing values will remain stored.\')" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_delete_form_field&field_id='.$f->id),'bsxmh_delete_form_field_'.$f->id)).'">Delete</a></td></tr>';}
        echo '</tbody></table>'; submit_button('Save Order','secondary'); echo '</form></div></div></div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){if(window.jQuery&&jQuery.fn.sortable){jQuery("#bsxmh-sort-fields").sortable({handle:".bsxmh-drag"});}});</script>';
    }
    private static function row(string $label,string $name,$value,bool $required=false,string $placeholder=''):void{echo '<p><label>'.$label.'</label><input class="regular-text" type="text" name="'.$name.'" value="'.esc_attr((string)$value).'" placeholder="'.esc_attr($placeholder).'" '.($required?'required':'').'></p>';}

    public static function validate_and_save( int $user_id, array $source, string $context='registration' ) {
        global $wpdb; $values=self::values($user_id); $errors=new WP_Error();
        foreach(self::fields(true) as $f){
            if('admin'===$f->visibility && 'admin'!==$context) continue;
            if('profile'===$context && !$f->member_editable) continue;
            if(in_array($f->field_type,array('heading','html'),true)) continue;
            $key='bsxmh_field_'.$f->field_key; $raw=$source[$key]??null;
            if(in_array($f->field_type,array('file','image'),true) && !empty($_FILES[$key]['name'])){
                $uploaded=self::handle_upload($f,$key); if(is_wp_error($uploaded)){$errors->add($f->field_key,$uploaded->get_error_message()); continue;} $value=$uploaded;
            } else { $value=self::sanitize_value($f,$raw); }
            if($f->is_required && (''===$value || array()===$value || null===$value)){$errors->add($f->field_key,sprintf('%s is required.',$f->label)); continue;}
            $rules=self::rules($f); $len=is_array($value)?count($value):mb_strlen((string)$value);
            if(!empty($rules['min_length']) && $len<(int)$rules['min_length']) $errors->add($f->field_key,sprintf('%s is too short.',$f->label));
            if(!empty($rules['max_length']) && $len>(int)$rules['max_length']) $errors->add($f->field_key,sprintf('%s is too long.',$f->label));
            if(!empty($rules['unique']) && ''!==$value){$users=get_users(array('meta_key'=>'bsxmh_custom_fields','fields'=>'ids')); foreach($users as $uid){if((int)$uid===$user_id)continue;$v=self::values((int)$uid);if(isset($v[$f->field_key])&&$v[$f->field_key]===$value){$errors->add($f->field_key,sprintf('%s is already in use.',$f->label));break;}}}
            $values[$f->field_key]=$value;
        }
        if($errors->has_errors()) return $errors;
        update_user_meta($user_id,'bsxmh_custom_fields',$values); return true;
    }
    private static function sanitize_value($f,$raw){
        if('checkbox'===$f->field_type) return empty($raw)?'0':'1';
        if('multiselect'===$f->field_type) return array_values(array_map('sanitize_text_field',(array)$raw));
        $raw=is_scalar($raw)?wp_unslash((string)$raw):'';
        switch($f->field_type){case 'email': return sanitize_email($raw); case 'url': return esc_url_raw($raw); case 'textarea': return sanitize_textarea_field($raw); case 'number': return ''===$raw?'':(string)(float)$raw; default:return sanitize_text_field($raw);}
    }
    private static function handle_upload($f,string $key){
        $o=self::options($f); $max=(int)($o['max_size']??2)*MB_IN_BYTES; if((int)$_FILES[$key]['size']>$max)return new WP_Error('large','The uploaded file is too large.');
        $allowed='image'===$f->field_type?array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'):array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf');
        require_once ABSPATH.'wp-admin/includes/file.php'; $upload=wp_handle_upload($_FILES[$key],array('test_form'=>false,'mimes'=>$allowed)); if(isset($upload['error']))return new WP_Error('upload',$upload['error']); return esc_url_raw($upload['url']);
    }

    public static function render_fields( string $context, int $user_id=0 ): string {
        $values=$user_id?self::values($user_id):array(); ob_start();
        foreach(self::fields(true) as $f){if('admin'===$f->visibility&&'admin'!==$context)continue;if('profile'===$context&&!$f->member_editable)continue;if('hidden'===$f->visibility)continue;$o=self::options($f);$value=$values[$f->field_key]??($o['default']??'');$name='bsxmh_field_'.$f->field_key;
            if('heading'===$f->field_type){echo '<h3>'.esc_html($f->label).'</h3>';continue;} if('html'===$f->field_type){echo '<div class="bsxmh-field-note">'.wp_kses_post($o['help']??'').'</div>';continue;}
            echo '<div class="bsxmh-form-field"><label>'.esc_html($f->label).($f->is_required?' <span aria-hidden="true">*</span>':'').'</label>';
            $req=$f->is_required?' required':''; $ph=esc_attr($o['placeholder']??'');
            if('textarea'===$f->field_type) echo '<textarea name="'.esc_attr($name).'" placeholder="'.$ph.'"'.$req.'>'.esc_textarea((string)$value).'</textarea>';
            elseif(in_array($f->field_type,array('select','radio','multiselect'),true)){ $choices=$o['options']??array(); if('radio'===$f->field_type){foreach($choices as $c)echo '<label class="bsxmh-inline"><input type="radio" name="'.esc_attr($name).'" value="'.esc_attr($c).'" '.checked($value,$c,false).$req.'> '.esc_html($c).'</label>';}else{echo '<select name="'.esc_attr($name).('multiselect'===$f->field_type?'[]':'').'" '.('multiselect'===$f->field_type?'multiple ':'').$req.'><option value="">Select</option>';foreach($choices as $c)echo '<option value="'.esc_attr($c).'" '.selected(is_array($value)?in_array($c,$value,true):$value===$c,true,false).'>'.esc_html($c).'</option>';echo '</select>';}}
            elseif('checkbox'===$f->field_type) echo '<label class="bsxmh-inline"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,'1',false).'> '.esc_html($o['help']??'').'</label>';
            elseif(in_array($f->field_type,array('file','image'),true)) echo '<input type="file" name="'.esc_attr($name).'" accept="'.('image'===$f->field_type?'image/jpeg,image/png,image/webp':'image/jpeg,image/png,image/webp,application/pdf').'">'.($value?'<p><a target="_blank" href="'.esc_url((string)$value).'">Current file</a></p>':'');
            else { $type=in_array($f->field_type,array('email','number','url','date'),true)?$f->field_type:('phone'===$f->field_type?'tel':'text'); echo '<input type="'.$type.'" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" placeholder="'.$ph.'"'.$req.'>'; }
            if(!empty($o['help'])&&'checkbox'!==$f->field_type)echo '<small>'.esc_html($o['help']).'</small>'; echo '</div>';
        }
        return (string)ob_get_clean();
    }
}
