How to test
====

    docker run -d -P --name es elasticsearch:5-alpine
    docker run --rm --publish=7474:7474 --publish=7687:7687 neo4j:3.3
    
    CI_BUILD_REF_NAME=1 \
        ES_URL='http://localhost:9200' \
        GRAPH_URL='http://neo4j:neo4j@localhost:7474' \
        NEO4J_AUTH="none" \
        phpunit -c resources/ci/phpunit.xml --stop-on-failure
