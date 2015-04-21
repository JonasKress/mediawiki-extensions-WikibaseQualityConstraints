<?php

namespace WikidataQuality\ConstraintReport\Tests\Specials\SpecialConstraintReport;

use Wikibase\Test\SpecialPageTestBase;
use WikidataQuality\ConstraintReport\Specials\SpecialConstraintReport;
use DataValues\StringValue;
use Wikibase\DataModel\Claim\Claim;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Lib\ClaimGuidGenerator;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\DataModel\Entity\EntityId;


/**
 * @covers WikidataQuality\ConstraintReport\Specials\SpecialConstraintReport
 *
 * @group Database
 * @group medium
 *
 * @uses   WikidataQuality\ConstraintReport\ConstraintCheck\Checker\ValueCountChecker
 * @uses   WikidataQuality\ConstraintReport\ConstraintCheck\Helper\ConstraintReportHelper
 * @uses   WikidataQuality\ConstraintReport\ConstraintCheck\ConstraintChecker
 * @uses   WikidataQuality\ConstraintReport\ConstraintCheck\Result\CheckResult
 * @uses   WikidataQuality\Html\HtmlTable
 * @uses   WikidataQuality\Html\HtmlTableCell
 * @uses   WikidataQuality\Html\HtmlTableHeader
 * @uses   WikidataQuality\Html\HtmlTableCell
 * @uses WikidataQuality\Result\ResultToViolationTranslator
 * @uses WikidataQuality\ConstraintReport\ConstraintCheck\Result\CheckResultToViolationTranslator
 * @uses WikidataQuality\Violations\Violation
 * @uses WikidataQuality\Violations\ViolationStore
 *
 * @author BP2014N1
 * @license GNU GPL v2+
 */
class SpecialConstraintReportTest extends SpecialPageTestBase {

    /**
     * Id of a item that (hopefully) does not exist.
     */
    const NOT_EXISTENT_ITEM_ID = 'Q5678765432345678';

    /**
     * @var EntityId[]
     */
    private static $idMap;

    /**
     * @var array
     */
    private static $claimGuids = array();

    /**
     * @var bool
     */
    private static $hasSetup;

    protected function setUp() {
        parent::setUp();
        $this->tablesUsed[ ] = CONSTRAINT_TABLE;
    }

    protected function newSpecialPage() {
        $page = new SpecialConstraintReport();

        $languageNameLookup = $this->getMock( 'Wikibase\Lib\LanguageNameLookup' );
        $languageNameLookup->expects( $this->any() )
            ->method( 'getName' )
            ->will( $this->returnValue( 'LANGUAGE NAME' ) );

        return $page;
    }

    /**
     * Adds temporary test data to database
     * @throws \DBUnexpectedError
     */
    public function addDBData() {
        if ( !self::$hasSetup ) {
            $store = WikibaseRepo::getDefaultInstance()->getEntityStore();

            $propertyP1 = Property::newFromType( 'string' );
            $store->saveEntity( $propertyP1, 'TestEntityP1', $GLOBALS[ 'wgUser' ], EDIT_NEW );
            self::$idMap[ 'P1' ] = $propertyP1->getId();

            $itemQ1 = new Item();
            $store->saveEntity( $itemQ1, 'TestEntityQ1', $GLOBALS[ 'wgUser' ], EDIT_NEW );
            self::$idMap[ 'Q1' ] = $itemQ1->getId();

            $claimGuidGenerator = new ClaimGuidGenerator();

            $dataValue = new StringValue( 'foo' );
            $snak = new PropertyValueSnak( self::$idMap[ 'P1' ], $dataValue );
            $claim = new Claim( $snak );
            $claimGuid = $claimGuidGenerator->newGuid( self::$idMap[ 'Q1' ] );
            self::$claimGuids[ 'P1' ] = $claimGuid;
            $claim->setGuid( $claimGuid );
            $statement = new Statement( $claim );
            $itemQ1->addClaim( $statement );

            $store->saveEntity( $itemQ1, 'TestEntityQ1', $GLOBALS[ 'wgUser' ], EDIT_UPDATE );

            self::$hasSetup = true;
        }

        // Truncate table
        $this->db->delete(
            CONSTRAINT_TABLE,
            '*'
        );


        $this->db->insert(
            CONSTRAINT_TABLE,
            array(
                array(
                    'constraint_guid' => '1',
                    'pid' => self::$idMap[ 'P1' ]->getNumericId(),
                    'constraint_type_qid' => 'Multi value',
                    'constraint_parameters' => '{}'
                ),
                array(
                    'constraint_guid' => '3',
                    'pid' => self::$idMap[ 'P1' ]->getNumericId(),
                    'constraint_type_qid' => 'Single value',
                    'constraint_parameters' => '{}'
                )
            )
        );
    }

    /**
     * @dataProvider executeProvider
     */
    public function testExecute( $subPage, $request, $userLanguage, $matchers ) {
        $request = new \FauxRequest( $request );

        // the added item is Q1; this solves the problem that the provider is executed before the test
        $id = self::$idMap[ 'Q1' ];
        $subPage = str_replace( '$id', $id->getSerialization(), $subPage );

        // assert matchers
        list( $output, ) = $this->executeSpecialPage( $subPage, $request, $userLanguage );
        foreach( $matchers as $key => $matcher ) {
            $this->assertTag( $matcher, $output, "Failed to assert output: $key" );
        }
    }

    public function executeProvider()
    {
        $userLanguage = 'qqx';
        $cases = array();
        $matchers = array();

        // Empty input
        $matchers['instructions'] = array(
            'tag' => 'p',
            'content' => '(wikidataquality-constraintreport-instructions)'
        );

        $matchers['instructions example'] = array(
            'tag' => 'p',
            'content' => '(wikidataquality-constraintreport-instructions-example)'
        );

        $matchers['entityId'] = array(
            'tag' => 'input',
            'attributes' => array(
                'placeholder' => '(wikidataquality-checkresult-form-entityid-placeholder)',
                'name' => 'entityId',
                'class' => 'wdq-checkresult-form-entity-id'
            )
        );

        $matchers['submit'] = array(
            'tag' => 'input',
            'attributes' => array(
                'class' => 'wbq-checkresult-form-submit',
                'type' => 'submit',
                'value' => '(wikidataquality-checkresult-form-submit-label)',
                'name' => 'submit'
            )
        );

        $cases['empty'] = array('', array(), $userLanguage, $matchers);

        // Invalid input
        $matchers['error'] = array(
            'tag' => 'p',
            'attributes' => array(
                'class' => 'wdq-checkresult-notice wdq-checkresult-notice-error'
            ),
            'content' => '(wikidataquality-checkresult-invalid-entity-id)'
        );

        $cases['invalid input 1'] = array( 'Qwertz', array(), $userLanguage, $matchers );
        $cases['invalid input 2'] = array( '300', array(), $userLanguage, $matchers );

        // Valid input but entity does not exist
        unset( $matchers['error'] );
        $matchers['error'] = array(
            'tag' => 'p',
            'attributes' => array(
                'class' => 'wdq-checkresult-notice wdq-checkresult-notice-error'
            ),
            'content' => '(wikidataquality-checkresult-not-existent-entity)'
        );

        $cases['valid input - not existing item'] = array( self::NOT_EXISTENT_ITEM_ID, array(), $userLanguage, $matchers );

        // Valid input and entity exists
        unset( $matchers['error'] );
        $matchers['result for'] = array(
            'tag' => 'h3',
            'content' => '(wikidataquality-checkresult-result-headline:'
        );

        $matchers['result table'] = array(
            'tag' => 'table',
            'attributes' => array(
                'class' => 'wikitable sortable jquery-tablesort'
            )
        );

        $matchers['column status'] = array(
            'tag' => 'th',
            'attributes' => array(
                'role' => 'columnheader button'
            ),
            'content' => '(wikidataquality-checkresult-result-table-header-status)'
        );

        $matchers['column claim'] = array(
            'tag' => 'th',
            'attributes' => array(
                'role' => 'columnheader button'
            ),
            'content' => '(wikidataquality-constraintreport-result-table-header-claim)'
        );

        $matchers['column constraint'] = array(
            'tag' => 'th',
            'attributes' => array(
                'role' => 'columnheader button'
            ),
            'content' => '(wikidataquality-constraintreport-result-table-header-constraint)'
        );

        $matchers['value status - violation'] = array(
            'tag' => 'span',
            'attributes' => array(
                'class' => 'wdq-status wdq-status-error'
            ),
            'content' => '(wikidataquality-checkresult-status-violation)'
        );

        $matchers['value status - compliance'] = array(
            'tag' => 'span',
            'attributes' => array(
                'class' => 'wdq-status wdq-status-success'
            ),
            'content' => '(wikidataquality-checkresult-status-compliance)'
        );

        $cases['valid input - existing item'] = array( '$id', array(), $userLanguage, $matchers );

        return $cases;
    }
}
 