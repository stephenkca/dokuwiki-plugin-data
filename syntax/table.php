<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once(dirname(__FILE__).'/../syntaxbase.php');

/**
 * We extend our own base class here
 */
class syntax_plugin_data_table extends syntaxbase_plugin_data {

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 155;
    }


    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *datatable(?: [ a-zA-Z0-9_]*)?-+\n.*?\n----+',$mode,'plugin_data_table');
    }


    /**
     * Handle the match - parse the data
     */
    function handle($match, $state, $pos, &$handler){
        // get lines and additional class
        $lines = explode("\n",$match);
        array_pop($lines);
        $class = array_shift($lines);
        $class = str_replace('datatable','',$class);
        $class = trim($class,'- ');

        $data = array();
        $data['classes'] = $class;

        // parse info
        foreach ( $lines as $line ) {
            // ignore comments
            $line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
            $line = str_replace('\\#','#',$line);
            $line = trim($line);
            if(empty($line)) continue;
            $line = preg_split('/\s*:\s*/',$line,2);
            $line[0] = strtolower($line[0]);

            $logic = 'OR';
            // handle line commands (we allow various aliases here)
            switch($line[0]){
                case 'select':
                case 'cols':
                        $cols = explode(',',$line[1]);
                        foreach($cols as $col){
                            $col = trim($col);
                            if(!$col) continue;
                            list($key,$type) = $this->_column($col);
                            $data['cols'][$key] = $type;

                            // fix type for special type
                            if($key == '%pageid%') $data['cols'][$key] = 'page';
                        }
                    break;
                case 'title':
                case 'titles':
                case 'head':
                case 'header':
                case 'headers':
                        $cols = explode(',',$line[1]);
                        foreach($cols as $col){
                            $col = trim($col);
                            $data['headers'][] = $col;
                        }
                    break;
                case 'order':
                case 'sort':
                        list($sort) = $this->_column($line[1]);
                        if(substr($sort,0,1) == '^'){
                            $data['sort'] = array(substr($sort,1),'DESC');
                        }else{
                            $data['sort'] = array($sort,'ASC');
                        }
                    break;
                case 'where':
                case 'filter':
                case 'filterand':
                case 'and':
                    $logic = 'AND';
                case 'filteror':
                case 'or':
                        if(preg_match('/^(.*?)(=|<|>|<=|>=|<>|!=|=~|~)(.*)$/',$line[1],$matches)){
                            list($key) = $this->_column(trim($matches[1]));
                            $val = trim($matches[3]);
                            $val = sqlite_escape_string($val); //pre escape
                            $com = $matches[2];
                            if($com == '<>'){
                                $com = '!=';
                            }elseif($com == '=~' || $com == '~'){
                                $com = 'LIKE';
                                $val = str_replace('*','%',$val);
                            }

                            $data['filter'][] = array('key'     => $key,
                                                      'value'   => $val,
                                                      'compare' => $com,
                                                      'logic'   => $logic
                                                     );
                        }
                    break;
                default:
                    msg("data plugin: unknown option '".hsc($line[0])."'",-1);
            }
        }

        // if no header titles were given, use column names
        if(!is_array($data['headers'])){
            foreach(array_keys($data['cols']) as $col){
                if($col == '%pageid%'){
                    $data['headers'][] = 'pagename'; #FIXME add lang string
                }else{
                    $data['headers'][] = $col;
                }
            }
        }

        return $data;
    }

    /**
     * Create output or save the data
     */
    function render($format, &$renderer, $data) {
        global $ID;

        if($format != 'xhtml') return false;
        if(!$this->_dbconnect()) return false;

        $sql = $this->_buildSQL($data); // handles GET params, too
        #dbg($data);
        #dbg($sql);

        // register our custom aggregate function
        sqlite_create_aggregate($this->db,'group_concat',
                                array($this,'_sqlite_group_concat_step'),
                                array($this,'_sqlite_group_concat_finalize'), 2);


        // run query
        $types = array_values($data['cols']);
        $res = sqlite_query($this->db,$sql);

        // build table
        $renderer->doc .= '<table class="inline dataplugin_table '.$data['classes'].'">';

        // build column headers
        $renderer->doc .= '<tr>';
        $cols = array_keys($data['cols']);
        foreach($data['headers'] as $num => $head){
            $col = $cols[$num];

            $renderer->doc .= '<th>';
            if($col == $data['sort'][0]){
                if($data['sort'][1] == 'ASC'){
                    $renderer->doc .= '<span>&darr;</span> ';
                    $col = '^'.$col;
                }else{
                    $renderer->doc .= '<span>&uarr;</span> ';
                }
            }
            $renderer->doc .= '<a href="'.wl($ID,array('datasrt'=>$col)).
                              '" title="FIXME trans sort">'.hsc($head).'</a>';

            $renderer->doc .= '</th>';
        }
        $renderer->doc .= '</tr>';

        // build data rows
        while ($row = sqlite_fetch_array($res, SQLITE_NUM)) {
            $renderer->doc .= '<tr>';
            foreach($row as $num => $col){
                $renderer->doc .= '<td>'.$this->_formatData($col,$types[$num],$renderer).'</td>';
            }
            $renderer->doc .= '</tr>';
        }
        $renderer->doc .= '</table>';

        return true;
    }

    /**
     * Builds the SQL query from the given data
     */
    function _buildSQL(&$data){
        $cnt    = 0;
        $tables = array();
        $select = array();
        $from   = '';
        $where  = '';
        $order  = '';


        // take overrides from HTTP GET params into account
        if($_GET['datasrt']){
            if($_GET['datasrt']{0} == '^'){
                $data['sort'] = array(substr($_GET['datasrt'],1),'DESC');
            }else{
                $data['sort'] = array($_GET['datasrt'],'ASC');
            }
        }


        // prepare the columns to show
        foreach (array_keys($data['cols']) as $col){
            if($col == '%pageid%'){
                $select[] = 'pages.page';
            }else{
                if(!$tables[$col]){
                    $tables[$col] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$col].' ON '.$tables[$col].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$col].".key = '".sqlite_escape_string($col)."'";
                }
                $select[] = 'group_concat('.$tables[$col].".value,', ')";
            }
        }

        // prepare sorting #FIXME add HTTP GET parameter suport here
        if($data['sort'][0]){
            $col = $data['sort'][0];

            if($col == '%pageid%'){
                $order = 'ORDER BY pages.page '.$data['sort'][1];
            }else{
                // sort by hidden column?
                if(!$tables[$col]){
                    $tables[$col] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$col].' ON '.$tables[$col].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$col].".key = '".sqlite_escape_string($col)."'";
                }

                $order = 'ORDER BY '.$tables[$col].'.value '.$data['sort'][1];
            }
        }else{
            $order = 'ORDER BY 1 ASC';
        }

        // add filters
        foreach((array)$data['filter'] as $filter){
            $col = $filter['key'];

            if($col == '%pageid%'){
                $where .= " AND pages.page ".$filter['compare']." '".$filter['value']."'";
            }else{
                // filter by hidden column?
                if(!$tables[$col]){
                    $tables[$col] = 'T'.(++$cnt);
                    $from  .= ' LEFT JOIN data AS '.$tables[$col].' ON '.$tables[$col].'.pid = pages.pid';
                    $from  .= ' AND '.$tables[$col].".key = '".sqlite_escape_string($col)."'";
                }

                $where .= ' '.$filter['logic'].' '.$tables[$col].'.value '.$filter['compare'].
                          " '".$filter['value']."'"; //value is already escaped
            }
        }

        // build the query
        $sql = "SELECT ".join(', ',$select)."
                  FROM pages $from
                 WHERE pages.pid = T1.pid $where
              GROUP BY pages.page
                $order";

        return $sql;
    }

    /**
     * Aggregation function for SQLite
     *
     * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
     */
    function _sqlite_group_concat_step(&$context, $string, $separator = ',') {
         $context['sep']    = $separator;
         $context['data'][] = $string;
    }

    /**
     * Aggregation function for SQLite
     *
     * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
     */
    function _sqlite_group_concat_finalize(&$context) {
         $context['data'] = array_unique($context['data']);
         return join($context['sep'],$context['data']);
    }
}

