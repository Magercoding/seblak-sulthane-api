<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        // Add search functionality
        $query = Category::query();

        if ($request->has('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        $categories = $query->paginate(10);
        return view('pages.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('pages.categories.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:categories,name|max:255',
            'description' => 'nullable|string',
        ]);

        $category = Category::create([
            'name' => $request->name,
            'description' => $request->description ?? null,
        ]);

        return redirect()->route('categories.index')->with('success', 'Category created successfully.');
    }

    public function edit($id)
    {
        $category = Category::findOrFail($id);
        return view('pages.categories.edit', compact('category'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
        ]);

        $category = Category::findOrFail($id);
        $category->update([
            'name' => $request->name,
            'description' => $request->description ?? $category->description,
        ]);

        return redirect()->route('categories.index')->with('success', 'Category updated successfully.');
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Check if the category has associated products
        if ($category->products()->exists()) {
            return redirect()->route('categories.index')
                ->with('warning', 'This category has active products. Please reassign or delete these products first.');
        }

        $category->delete();

        return redirect()->route('categories.index')
            ->with('success', 'Category deleted successfully.');
    }

    /**
     * Apply common styling to a spreadsheet
     */
    private function applyCommonStyles($spreadsheet, $title)
    {
        // Set spreadsheet metadata
        $spreadsheet->getProperties()
            ->setCreator('Seblak Sulthane')
            ->setLastModifiedBy('Seblak Sulthane')
            ->setTitle($title)
            ->setSubject('Seblak Sulthane Categories')
            ->setDescription('Generated by Seblak Sulthane Management System');

        // Common header style
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        return $headerStyle;
    }

    /**
     * Add instructions section to a spreadsheet
     */
    private function addInstructionsSection($sheet, $instructions, $startRow, $column = 'D')
    {
        // Instruction Header Styling
        $instructionHeaderStyle = [
            'font' => [
                'bold' => true,
                'size' => 14,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '305496']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        // Instructions section to the side
        $sheet->setCellValue($column . $startRow, 'INSTRUCTIONS');
        $sheet->getStyle($column . $startRow)->applyFromArray($instructionHeaderStyle);
        $sheet->getRowDimension($startRow)->setRowHeight(30);

        // Instruction content styling
        $instructionContentStyle = [
            'font' => [
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9E1F2'] // Light blue background
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true
            ],
        ];

        // Create a single instruction cell with all instructions
        $instructionText = implode("\n\n", $instructions);
        $sheet->setCellValue($column . ($startRow + 1), $instructionText);
        $sheet->getStyle($column . ($startRow + 1) . ':' . $column . ($startRow + 7))->applyFromArray($instructionContentStyle);
        $sheet->mergeCells($column . ($startRow + 1) . ':' . $column . ($startRow + 7));

        // Set column width for instructions
        $sheet->getColumnDimension($column)->setWidth(50);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');

        try {
            DB::beginTransaction();

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
            $spreadsheet = $reader->load($file);
            $rows = $spreadsheet->getActiveSheet()->toArray();

            // Skip header row
            array_shift($rows);

            $importCount = 0;
            $errors = [];
            $duplicates = [];

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2; // +2 because row 1 is the header and arrays are 0-indexed

                // Skip empty rows
                if (empty($row[0])) {
                    continue;
                }

                try {
                    // Sanitize name value
                    $name = trim($row[0]);
                    $description = isset($row[1]) ? trim($row[1]) : null;

                    // Check if name is too long (database constraint)
                    if (strlen($name) > 255) {
                        $errors[] = "Row {$rowNumber}: Category name exceeds maximum length (255 characters)";
                        continue;
                    }

                    // Check for duplicates in database
                    $existingCategory = Category::where('name', $name)->first();
                    if ($existingCategory) {
                        $duplicates[] = "Row {$rowNumber}: Category '{$name}' already exists (ID: {$existingCategory->id})";
                        continue;
                    }

                    // Check for duplicates in current import batch
                    $isDuplicate = false;
                    foreach ($rows as $checkIndex => $checkRow) {
                        if ($checkIndex !== $index && $checkIndex < $index && !empty($checkRow[0]) && trim($checkRow[0]) === $name) {
                            $isDuplicate = true;
                            $duplicates[] = "Row {$rowNumber}: Duplicate category '{$name}' found in row " . ($checkIndex + 2);
                            break;
                        }
                    }

                    if ($isDuplicate) {
                        continue;
                    }

                    // Create category
                    Category::create([
                        'name' => $name,
                        'description' => $description,
                    ]);

                    $importCount++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNumber}: " . $e->getMessage();
                }
            }

            DB::commit();

            // Build a comprehensive message
            $message = "Successfully imported {$importCount} categories.";

            if (!empty($duplicates)) {
                $dupMessage = count($duplicates) <= 3
                    ? implode("; ", $duplicates)
                    : implode("; ", array_slice($duplicates, 0, 3)) . "... and " . (count($duplicates) - 3) . " more";

                $message .= " {$dupMessage}";
                return redirect()->route('categories.index')->with('warning', $message);
            }

            if (!empty($errors)) {
                $errMessage = count($errors) <= 3
                    ? implode("; ", $errors)
                    : implode("; ", array_slice($errors, 0, 3)) . "... and " . (count($errors) - 3) . " more";

                $message .= " Encountered errors: {$errMessage}";
                return redirect()->route('categories.index')->with('warning', $message);
            }

            return redirect()->route('categories.index')->with('success', $message);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('categories.index')
                ->with('error', 'Error importing categories: ' . $e->getMessage());
        }
    }

    public function template()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Apply common styling
        $headerStyle = $this->applyCommonStyles($spreadsheet, 'Category Import Template');

        // Headers
        $headers = ['Name', 'Description (Optional)'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(65 + $index) . '1', $header);
        }

        // Apply header styling
        $sheet->getStyle('A1:B1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Add sample data
        $sampleData = [
            'Makanan Utama',
            'Kategori untuk menu makanan utama'
        ];

        foreach ($sampleData as $index => $value) {
            $sheet->setCellValue(chr(65 + $index) . '2', $value);
        }

        // Style for sample row
        $sampleRowStyle = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2EFDA']
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A2:B2')->applyFromArray($sampleRowStyle);

        // Add more sample rows for different types of categories
        $moreSamples = [
            ['Minuman', 'Kategori untuk berbagai jenis minuman'],
            ['Camilan', 'Kategori untuk makanan ringan dan camilan'],
        ];

        $row = 3;
        foreach ($moreSamples as $sample) {
            $sheet->setCellValue('A' . $row, $sample[0]);
            $sheet->setCellValue('B' . $row, $sample[1]);

            // Alternate row colors
            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            } else {
                $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E2EFDA');
            }

            // Add borders
            $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);

            $row++;
        }

        // Style for empty data rows
        $dataRowStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F9F9F9']
            ],
        ];

        // Apply styling to empty data rows - increased to 200 rows
        $sheet->getStyle('A' . $row . ':B200')->applyFromArray($dataRowStyle);

        // Add alternating row colors for the rest of the template
        for ($i = $row; $i <= 200; $i++) {
            if ($i % 2 == 1) { // Odd rows
                $sheet->getStyle('A' . $i . ':B' . $i)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            }
        }

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(30); // Name
        $sheet->getColumnDimension('B')->setWidth(50); // Description

        // Add instructions section
        $instructions = [
            "1. Fill out category details starting from row 5",
            "2. The 'Name' column is required and must be unique",
            "3. The 'Description' column is optional",
            "4. Do not modify column headers in row 1",
            "5. Rows 2-4 contain examples - feel free to delete or keep them",
            "6. This template supports up to 200 category entries",
            "7. Categories with existing names will be skipped during import"
        ];
        $this->addInstructionsSection($sheet, $instructions, 1, 'D');

        // Add field explanations
        $fieldExplanationHeaderStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '548235'] // Green header
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        $sheet->setCellValue('D10', 'FIELD EXPLANATIONS');
        $sheet->getStyle('D10')->applyFromArray($fieldExplanationHeaderStyle);

        $fieldExplanations = [
            'Name' => 'Category name (required), e.g., "Makanan Utama"',
            'Description' => 'Optional description of the category'
        ];

        $fieldRow = 11;
        foreach ($fieldExplanations as $field => $explanation) {
            $sheet->setCellValue('D' . $fieldRow, "$field: $explanation");
            $fieldRow++;
        }

        $fieldExplanationStyle = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2EFDA'] // Light green background
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
        ];

        $sheet->getStyle('D11:D12')->applyFromArray($fieldExplanationStyle);

        // Freeze the header row
        $sheet->freezePane('A2');

        // Set the auto-filter
        $sheet->setAutoFilter('A1:B200');

        $writer = new Xlsx($spreadsheet);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="categories_template.xlsx"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
    }

    public function export()
    {
        try {
            $categories = Category::all();

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Apply common styling
            $headerStyle = $this->applyCommonStyles($spreadsheet, 'Categories Export');

            // Set headers
            $headers = [
                'ID',
                'Name',
                'Description',
                'Products Count',
                'Created At',
                'Updated At'
            ];

            foreach ($headers as $index => $header) {
                $sheet->setCellValue(chr(65 + $index) . '1', $header);
            }

            // Apply header styling
            $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(25);

            // Add data rows
            $row = 2;
            foreach ($categories as $category) {
                $sheet->setCellValue('A' . $row, $category->id);
                $sheet->setCellValue('B' . $row, $category->name);
                $sheet->setCellValue('C' . $row, $category->description ?? '');
                $sheet->setCellValue('D' . $row, $category->products()->count());
                $sheet->setCellValue('E' . $row, $category->created_at->format('Y-m-d H:i:s'));
                $sheet->setCellValue('F' . $row, $category->updated_at->format('Y-m-d H:i:s'));

                // Alternate row colors
                if ($row % 2 == 0) {
                    $sheet->getStyle('A' . $row . ':F' . $row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F2F2F2');
                }

                $row++;
            }

            // Set border for all data
            $borderStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ];
            $sheet->getStyle('A1:F' . ($row - 1))->applyFromArray($borderStyle);

            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(10); // ID
            $sheet->getColumnDimension('B')->setWidth(30); // Name
            $sheet->getColumnDimension('C')->setWidth(50); // Description
            $sheet->getColumnDimension('D')->setWidth(15); // Products Count
            $sheet->getColumnDimension('E')->setWidth(20); // Created At
            $sheet->getColumnDimension('F')->setWidth(20); // Updated At

            // Add export info on the right side
            $sheet->setCellValue('H1', 'Exported on: ' . now()->format('d M Y H:i:s'));
            $sheet->setCellValue('H2', 'Total Categories: ' . ($row - 2));

            $exportInfoStyle = [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DDEBF7'] // Light blue background
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ];
            $sheet->getStyle('H1:H2')->applyFromArray($exportInfoStyle);

            // Set column width for export info
            $sheet->getColumnDimension('H')->setWidth(35);

            // Freeze the header row
            $sheet->freezePane('A2');

            // Set the auto-filter
            $sheet->setAutoFilter('A1:F' . ($row - 1));

            $writer = new Xlsx($spreadsheet);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="categories_export_' . date('Y-m-d') . '.xlsx"');
            header('Cache-Control: max-age=0');

            $writer->save('php://output');
            exit();
        } catch (\Exception $e) {
            return redirect()->route('categories.index')
                ->with('error', 'Error exporting categories: ' . $e->getMessage());
        }
    }

    public function exportForUpdate()
    {
        try {
            $categories = Category::all();

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Apply common styling
            $headerStyle = $this->applyCommonStyles($spreadsheet, 'Categories Bulk Update Template');

            // Set headers
            $headers = [
                'ID',
                'Name',
                'Description'
            ];

            foreach ($headers as $index => $header) {
                $sheet->setCellValue(chr(65 + $index) . '1', $header);
            }

            // Apply header styling
            $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(25);

            // Add data rows
            $row = 2;
            foreach ($categories as $category) {
                $sheet->setCellValue('A' . $row, $category->id);
                $sheet->setCellValue('B' . $row, $category->name);
                $sheet->setCellValue('C' . $row, $category->description ?? '');

                // Protect ID column from editing
                $sheet->getStyle('A' . $row)->getProtection()
                    ->setLocked(true);

                // Highlight ID column to indicate it should not be changed
                $sheet->getStyle('A' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('DDDDDD');

                // Alternate row colors for data rows
                if ($row % 2 == 0) {
                    $sheet->getStyle('B' . $row . ':C' . $row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F2F2F2');
                }

                $row++;
            }

            // Set border for all data
            $borderStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ];
            $sheet->getStyle('A1:C' . ($row - 1))->applyFromArray($borderStyle);

            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(10); // ID
            $sheet->getColumnDimension('B')->setWidth(30); // Name
            $sheet->getColumnDimension('C')->setWidth(50); // Description

            // Add instructions section
            $instructions = [
                "1. DO NOT modify the ID column (column A)",
                "2. The 'Name' column must be unique across all categories",
                "3. You may modify the 'Name' and 'Description' columns",
                "4. Leave cells unchanged for values you don't want to update",
                "5. Each row represents an existing category",
                "6. When finished, save and upload the file to perform updates"
            ];
            $this->addInstructionsSection($sheet, $instructions, 1, 'E');

            // Warning for ID column
            $warningStyle = [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'C65911'] // Orange header
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ];

            $sheet->setCellValue('E12', 'WARNING');
            $sheet->getStyle('E12')->applyFromArray($warningStyle);

            $sheet->setCellValue('E13', 'Do not modify the ID values in column A. These are used to identify which categories to update.');
            $warningTextStyle = [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FCE4D6'] // Light orange background
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
                'alignment' => [
                    'wrapText' => true,
                ],
            ];
            $sheet->getStyle('E13')->applyFromArray($warningTextStyle);

            // Freeze the ID column and header row
            $sheet->freezePane('B2');

            // Set the auto-filter
            $sheet->setAutoFilter('A1:C' . ($row - 1));

            // Protect the worksheet to prevent ID column editing
            $sheet->getProtection()->setSheet(true);

            // Allow editing of data cells
            for ($r = 2; $r < $row; $r++) {
                $sheet->getStyle('B' . $r . ':C' . $r)->getProtection()
                    ->setLocked(false);
            }

            $writer = new Xlsx($spreadsheet);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="categories_for_update.xlsx"');
            header('Cache-Control: max-age=0');

            $writer->save('php://output');
            exit();
        } catch (\Exception $e) {
            return redirect()->route('categories.index')
                ->with('error', 'Error exporting categories for update: ' . $e->getMessage());
        }
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');

        try {
            DB::beginTransaction();

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
            $spreadsheet = $reader->load($file);
            $rows = $spreadsheet->getActiveSheet()->toArray();

            // Skip header row
            array_shift($rows);

            $updateCount = 0;
            $errors = [];
            $skippedCount = 0;
            $duplicateNames = [];

            // First pass: check for duplicate names in the import file
            $nameMap = [];
            foreach ($rows as $index => $row) {
                if (empty($row[0]) || empty($row[1])) continue;

                $id = $row[0];
                $name = trim($row[1]);

                if (isset($nameMap[$name])) {
                    $duplicateNames[] = "Duplicate name '{$name}' found in rows " . ($nameMap[$name] + 2) . " and " . ($index + 2);
                } else {
                    $nameMap[$name] = $index;
                }
            }

            // Return early if duplicate names are found
            if (!empty($duplicateNames)) {
                DB::rollBack();
                return redirect()->route('categories.index')
                    ->with('error', 'Import failed: Duplicate category names detected in the upload file: ' . implode('; ', $duplicateNames));
            }

            foreach ($rows as $index => $row) {
                // Skip empty rows
                if (empty($row[0])) continue;

                try {
                    $category = Category::find($row[0]);

                    if (!$category) {
                        $errors[] = "Row " . ($index + 2) . ": Category with ID {$row[0]} not found";
                        continue;
                    }

                    // Check if the name already exists for another category
                    if (!empty($row[1]) && $row[1] !== $category->name) {
                        $existingCategory = Category::where('name', $row[1])
                            ->where('id', '!=', $category->id)
                            ->first();

                        if ($existingCategory) {
                            $errors[] = "Row " . ($index + 2) . ": Cannot update to name '{$row[1]}' as it is already used by category ID {$existingCategory->id}";
                            continue;
                        }
                    }

                    // Update only if fields have been modified
                    $updates = [];
                    $hasChanges = false;

                    // Check and update name if changed
                    if (!empty($row[1]) && $row[1] !== $category->name) {
                        $updates['name'] = $row[1];
                        $hasChanges = true;
                    }

                    // Check and update description if changed
                    if (isset($row[2]) && $row[2] !== $category->description) {
                        $updates['description'] = $row[2];
                        $hasChanges = true;
                    }

                    // Only update if there are changes
                    if ($hasChanges) {
                        $category->update($updates);
                        $updateCount++;
                        \Log::info("Updated category ID {$category->id}: " . json_encode($updates));
                    } else {
                        $skippedCount++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
                    \Log::error("Error processing row " . ($index + 2) . ": " . $e->getMessage());
                }
            }

            DB::commit();

            // Build a comprehensive message
            $message = "Category update summary: {$updateCount} categories updated";
            if ($skippedCount > 0) {
                $message .= ", {$skippedCount} categories unchanged";
            }

            if (!empty($errors)) {
                if (count($errors) <= 3) {
                    $message .= ". However, there were some errors: " . implode("; ", $errors);
                    return redirect()->route('categories.index')->with('warning', $message);
                } else {
                    $message .= ". However, there were " . count($errors) . " errors. First few: " .
                        implode("; ", array_slice($errors, 0, 3)) . "...";
                    return redirect()->route('categories.index')->with('warning', $message);
                }
            }

            return redirect()->route('categories.index')->with('success', $message);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('categories.index')
                ->with('error', 'Error updating categories: ' . $e->getMessage());
        }
    }

    public function deleteAll()
    {
        try {
            DB::beginTransaction();

            // Log start of operation
            \Log::info('Starting deleteAll process for categories');

            // Check if any categories have associated products
            $categoriesWithProducts = Category::whereHas('products')->get();

            if ($categoriesWithProducts->isNotEmpty()) {
                // Prepare detailed information about categories with products
                $categoryInfo = $categoriesWithProducts->map(function ($category) {
                    $productCount = $category->products()->count();
                    return "{$category->name} (ID: {$category->id}, Products: {$productCount})";
                })->join(', ');

                // Roll back and return message
                DB::rollBack();
                return redirect()->route('categories.index')
                    ->with('warning', "Cannot delete all categories. The following categories still have associated products: {$categoryInfo}. Please reassign or delete these products first.");
            }

            // Track how many categories will be deleted
            $categoryCount = Category::count();

            if ($categoryCount === 0) {
                DB::rollBack();
                return redirect()->route('categories.index')
                    ->with('info', 'No categories found to delete.');
            }

            // Disable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Delete all categories
            Category::query()->delete();

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Commit the transaction
            DB::commit();

            // Log successful deletion
            \Log::info("Successfully deleted all {$categoryCount} categories");

            return redirect()->route('categories.index')
                ->with('success', "All {$categoryCount} categories have been successfully deleted.");
        } catch (\Exception $e) {
            // Log error
            \Log::error('Error in deleteAll categories: ' . $e->getMessage());

            // Rollback transaction if still active
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // Make sure foreign key checks are re-enabled
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            return redirect()->route('categories.index')
                ->with('error', 'Error deleting categories: ' . $e->getMessage());
        }
    }
}
