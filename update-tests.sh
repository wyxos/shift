#!/bin/bash

# Find all test files
TEST_FILES=$(find resources/js/__tests__ -name "*.test.ts")

# Loop through each test file
for file in $TEST_FILES; do
  echo "Processing $file..."

  # Check if the file contains the problematic setData pattern
  if grep -q "wrapper.setData" "$file"; then
    echo "Found setData in $file, updating..."

    # Update the Inertia.js mock to use our utility function
    sed -i '' -e '/vi.mock.*@inertiajs\/vue3/,/})/c\
// Mock Inertia components and functions\
vi.mock("@inertiajs/vue3", () => {\
  // Import the utility function\
  const { createInertiaFormMock } = require("../setupTests");\
  \
  // Create a form mock with default values\
  return createInertiaFormMock();\
})' "$file"

    # Add import for the utility function if not already present
    if ! grep -q "import.*setupTests" "$file"; then
      sed -i '' -e '1s/^/import { createInertiaFormMock } from "..\\/setupTests";\n/' "$file"
    fi

    # Replace setData calls with the new approach
    sed -i '' -e 's/await wrapper.setData({/\/\/ Get the form state from the mock\n    const { _formState } = await import("@inertiajs\/vue3");\n    \n    \/\/ Set the form state before mounting the component/g' "$file"
    sed -i '' -e 's/form: {/_formState = {/g' "$file"
    sed -i '' -e '/^[ ]*}[ ]*})$/d' "$file"

    echo "Updated $file"
  fi
done

echo "All test files processed!"
