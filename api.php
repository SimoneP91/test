<?php
include 'config.php';
include 'consts.php';


if( $_SERVER['REQUEST_METHOD'] != 'GET' && $_SERVER['QUERY_STRING'] == "" ){
    http_response_code(401);
    echo json_encode( 'only GET' );
}


parse_str( $_SERVER['QUERY_STRING'] , $param );



$api = (new api())->getData( $param );




class api
{

    private $DbConn;
    private $val_lang = [ 'italian', 'english'] ;
    private $tables = ['node_tree','node_tree_names'];
    private $idNode;
    private $language;
    private $search;
    private $page_num;
    private $page_size;
    private $json_error;
    private $json_status= 200;
    private $risultato;

    public function getData( array $param ) // receives the parameters, checks them, validates them, acquires the results and returns them
    {
        $this->DbConn = DbConn::getInstance()->getConnection();
        $this->idNode = (int) $param['idNode'] ?? null;
        $this->language = strtolower($param['language']) ?? "" ;
        $this->page_size = $param['page_size'] ?? 100;
        $this->search = $param['search'] ?? "" ;
        $this->page_num = $param['page_num'] ?? 0;


         //parameter validation
        if ($this->checkTableAvailability() && $this->isValidNodeID( ) && $this->isValidLanguage() && $this->isValidPageSize() ){
          //  $risultato = $this->getResult();
          $this->risultato = [];
            $this->risultato = $this->getResult();
        } else {
          $this->risultato = new stdClass(); // instantiating objects to prevent association warning for the error string to a non object class
          $this->risultato->json_error = $this->json_error;
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code( $this->json_status );
        echo json_encode( $this->risultato, JSON_UNESCAPED_UNICODE ); // adding 2nd parameter for accented letter(idnode 12)

    }


    private function isValidNodeID( ) // check if the node recieved is valid, integer and available in the table
    {
        if ( !is_integer( $this->idNode ) ){
            $this->json_error = 'The requested idNode is not an integer, please provide an integer parameter'. $this->idNode ;
            $this->json_status = 400;
            return false;
        }

        if ( $this->idNode === 0){
            $this->json_error = 'Error! unknown idNode provided';
            $this->json_status = 500;
            return false;
        }

        $sth = $this->DbConn->prepare('SELECT nt.idNode FROM node_tree nt WHERE nt.idNode = :idNode ;');
        $sth->bindValue(':idNode', $this->idNode, PDO::PARAM_INT);

        $sth->execute();

        if( !$sth->fetchAll() ){
            $this->json_error = 'The requested idNode is not available';
            $this->json_status = 404;
            return false;
        }

        return true;
    }

    private function isValidPageSize( ) //check if page size is valid
    {
        if( $this->page_size >= 0 && $this->page_size <= 1000){
            return true;
        }else{
          $this->json_error = 'Incorrect age size, it must be between 0 and 1000';
            $this->json_status = 400;
            return false;
        }
    }



    private function isValidLanguage(  ) // check if language is valid
    {
        if( !in_array( $this->language , $this->val_lang ) ){
            $this->json_error = 'Incorrect language, please provide a correct language' ;
            $this->json_status = 404;
            return false;
        }
        return true;
    }

    // after retrieving all parameters and ensuring they are correct,
    // build query and return the result
    private function getResult()
    {
        $SQL = 'SELECT DISTINCT
                    children.*,
                    ntn.language,
                    ntn.nodeName,
                    (childCount.children_count-children.level) AS children_count

                FROM
                        (
                        SELECT c2.idNode, COUNT(DISTINCT c2.level) AS children_count
                        FROM node_tree p2
                        INNER JOIN node_tree c2 ON c2.iLeft BETWEEN p2.iLeft AND p2.iRight
                        INNER JOIN node_tree_names ntn2 ON c2.idNode = ntn2.idNode
                    ) AS childCount
                    JOIN node_tree children
                    JOIN node_tree parent ON children.iLeft BETWEEN parent.iLeft AND parent.iRight
                                                            AND children.idNode = :idNode
                    INNER JOIN node_tree_names ntn ON children.idNode = ntn.idNode
                    WHERE ntn.language = :language ';


        if( $this->search ){ // add search string to main query if requested
            $SQL .= ' AND nodeName LIKE :nodeName ';
            $sth = $this->DbConn->prepare($SQL);
            $sth->bindValue(':nodeName', '%'.$this->nodeName.'%', PDO::PARAM_STR);
        } else {
            $sth = $this->DbConn->prepare($SQL);
        }

        $sth->bindValue(':idNode', $this->idNode, PDO::PARAM_INT);
        $sth->bindValue(':language', $this->language, PDO::PARAM_STR);
        $sth->execute();
        $result = $sth->fetchAll();

        $i=0;
        foreach ( $result as $val) { // for each result, assign to index the fetched value
            $data[$i]['node_id'] = $val['idNode'];
            $data[$i]['name'] = $val['nodeName'];
            $data[$i]['children_count'] = $val['children_count'];
            $data[$i]['page_num'] = $this->page_num;
            $data[$i]['page_size'] = $this->page_size;
            $i++;
        }

        // return array of data
        return $data;
    }

    private function checkTableAvailability()
    {
      $this->DbConn = DbConn::getInstance()->getConnection();

      foreach ($this->tables as $table ) {
        $SQL="SELECT * FROM information_schema.`tables` WHERE TABLE_NAME = '".$table."';";
        $sth = $this->DbConn->prepare($SQL);
        $sth->execute();

        if( !$sth->fetch() ) {
            $this->json_error = 'Warning! The table '.$table.' is not available in the database!';
            $this->json_status = 404;
            return false;
        }
        return true;
      }
    }

}
