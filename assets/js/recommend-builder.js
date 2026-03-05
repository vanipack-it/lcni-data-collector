function lcniInitRecommendBuilder() {
    const config = window.LCNI_RECOMMEND || {};
    const columnsMap = config.columnsMap || {};
    const addButton = document.getElementById("lcni-recommend-add-condition");
    const host = document.getElementById("lcni-recommend-conditions");
    const jsonField = document.getElementById("lcni_recommend_entry_conditions");

    if (!addButton || !host || !jsonField) {
        return;
    }

    if (addButton.dataset.builderReady === "1") {
        return;
    }

    addButton.dataset.builderReady = "1";

    const operators = ["=", "!=", ">", ">=", "<", "<=", "contains", "not_contains"];
    const defaultConditionCount = 5;
    const conditions = [];

    function makeEmptyCondition() {
        const firstTable = Object.keys(columnsMap)[0] || "";

        return {
            table: firstTable,
            field: "",
            operator: "=",
            value: ""
        };
    }

    function normalizeCondition(raw) {
        const source = raw && typeof raw === "object" ? raw : {};

        return {
            table: String(source.table || ""),
            field: String(source.field || ""),
            operator: String(source.operator || "="),
            value: String(source.value || "")
        };
    }

    function hydrateInitialConditions() {
        try {
            const parsed = JSON.parse(String(jsonField.value || "{}"));
            const parsedConditions = parsed && Array.isArray(parsed.conditions) ? parsed.conditions : [];
            const source = parsedConditions;
            source.forEach(function (item) {
                conditions.push(normalizeCondition(item));
            });
        } catch (error) {
            // keep fallback defaults below
        }

        if (!conditions.length) {
            for (let index = 0; index < defaultConditionCount; index += 1) {
                conditions.push(makeEmptyCondition());
            }
        }
    }

    function makeSelect(options, selected) {
        const select = document.createElement("select");
        select.className = "regular-text";

        options.forEach(function (value) {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = value;
            if (value === selected) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        return select;
    }

    function syncJson() {
        const normalized = conditions
            .map(normalizeCondition)
            .filter(function (item) {
                return item.table && item.field && item.value !== "";
            });

        jsonField.value = JSON.stringify(
            {
                logic: "AND",
                conditions: normalized
            },
            null,
            2
        );
    }

    function render() {
        host.innerHTML = "";

        if (!conditions.length) {
            const empty = document.createElement("em");
            empty.textContent = "Chưa có điều kiện. Bấm Add condition để tạo điều kiện entry.";
            host.appendChild(empty);
            syncJson();
            return;
        }

        const tables = Object.keys(columnsMap);

        conditions.forEach(function (condition, index) {
            const row = document.createElement("div");
            row.className = "lcni-recommend-condition-item";

            const tableSelect = makeSelect(tables, condition.table);
            tableSelect.addEventListener("change", function () {
                condition.table = tableSelect.value;
                condition.field = "";
                render();
            });
            row.appendChild(tableSelect);

            const fieldSelect = document.createElement("select");
            fieldSelect.className = "regular-text";
            fieldSelect.innerHTML = '<option value="">Select field</option>';

            const columns = columnsMap[condition.table] || [];
            columns.forEach(function (column) {
                const option = document.createElement("option");
                option.value = column.field;
                option.textContent = column.field;
                if (column.field === condition.field) {
                    option.selected = true;
                }
                fieldSelect.appendChild(option);
            });

            fieldSelect.addEventListener("change", function () {
                condition.field = fieldSelect.value;
                syncJson();
            });
            row.appendChild(fieldSelect);

            const operatorSelect = makeSelect(operators, condition.operator);
            operatorSelect.className = "small-text";
            operatorSelect.addEventListener("change", function () {
                condition.operator = operatorSelect.value;
                syncJson();
            });
            row.appendChild(operatorSelect);

            const valueInput = document.createElement("input");
            valueInput.type = "text";
            valueInput.className = "regular-text";
            valueInput.placeholder = "Compare value";
            valueInput.value = condition.value;
            valueInput.addEventListener("input", function () {
                condition.value = valueInput.value;
                syncJson();
            });
            row.appendChild(valueInput);

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "button-link-delete";
            remove.textContent = "Xóa";
            remove.addEventListener("click", function () {
                conditions.splice(index, 1);
                render();
            });
            row.appendChild(remove);

            host.appendChild(row);
        });

        syncJson();
    }

    addButton.addEventListener("click", function () {
        conditions.push(makeEmptyCondition());

        render();
    });

    hydrateInitialConditions();
    render();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", lcniInitRecommendBuilder);
} else {
    lcniInitRecommendBuilder();
}
