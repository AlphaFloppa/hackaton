CREATE SCHEMA IF NOT EXISTS mesh_routing;

CREATE TABLE mesh_routing.routing_decisions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id uuid NOT NULL,
    revision integer NOT NULL CHECK (revision > 0),
    previous_revision integer GENERATED ALWAYS AS (
        CASE WHEN revision > 1 THEN revision - 1 END
    ) STORED,
    chosen text NOT NULL CHECK (chosen IN ('MESH', 'HUB_TRUNK')),
    preference text NOT NULL CHECK (preference IN ('COST', 'TIME')),
    reason text NOT NULL,
    response jsonb NOT NULL,
    selected_path jsonb NOT NULL,
    model_snapshot jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (order_id, revision),
    FOREIGN KEY (order_id, previous_revision)
        REFERENCES mesh_routing.routing_decisions (order_id, revision)
);

CREATE TABLE mesh_routing.graph_nodes (
    decision_id bigint NOT NULL REFERENCES mesh_routing.routing_decisions (id) ON DELETE CASCADE,
    node_key text NOT NULL,
    kind text NOT NULL,
    name text NOT NULL,
    city_id text NOT NULL,
    PRIMARY KEY (decision_id, node_key)
);

CREATE TABLE mesh_routing.graph_edges (
    decision_id bigint NOT NULL,
    edge_key text NOT NULL,
    from_node text NOT NULL,
    to_node text NOT NULL,
    kind text NOT NULL,
    cost_cents bigint NOT NULL CHECK (cost_cents >= 0),
    minutes integer NOT NULL CHECK (minutes >= 0),
    label text NOT NULL,
    PRIMARY KEY (decision_id, edge_key),
    FOREIGN KEY (decision_id, from_node)
        REFERENCES mesh_routing.graph_nodes (decision_id, node_key) ON DELETE CASCADE,
    FOREIGN KEY (decision_id, to_node)
        REFERENCES mesh_routing.graph_nodes (decision_id, node_key) ON DELETE CASCADE
);

CREATE TABLE mesh_routing.order_calcs (
    decision_id bigint PRIMARY KEY REFERENCES mesh_routing.routing_decisions (id) ON DELETE CASCADE,
    model_version text NOT NULL,
    forecast jsonb NOT NULL
);
