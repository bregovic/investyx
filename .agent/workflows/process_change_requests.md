---
description: Process the next priority Change Request (Development -> Testing)
---

1. **Check for Tasks**:
   - Use the `read_url_content` tool to fetch pending tasks:
     Target: `https://hollyhop.cz/investyx/agent_api.php?key=AgntKey_998877&action=list_dev`

2. **Select Task**:
   - Parse the JSON response.
   - If `data` is empty or null, STOP. There are no tasks to process.
   - Otherwise, pick the **first** task in the `data` array (it is already sorted by priority).
   - Note the `id`, `subject`, and `description`.

3. **Implement**:
   - Based on the `subject` and `description`, locate the relevant files and implement the requested feature or fix.
   - **Build** the frontend if necessary (`npm run build`).
   - **Deploy** the changes (`manual_deploy.ps1`).

4. **Update Status**:
   - Once deployed, mark the ticket as `Testing` using the Agent API.
   - Construct the URL carefully, ensuring the `note` parameter is URL-encoded.
   - Target pattern: `https://hollyhop.cz/investyx/agent_api.php?key=AgntKey_998877&action=update&id=<TASK_ID>&status=Testing&note=<YOUR_COMMENT>`
   - Use `read_url_content` on this URL.

5. **Report**:
   - Inform the user: "Zpracoval jsem požadavek **[Subject]**. Nasazeno a přesunuto do stavu **Testing**."
