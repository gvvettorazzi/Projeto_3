<?php declare(strict_types = 1);

namespace ApiGen;

use ApiGen\Analyzer\AnalyzeResult;
use ApiGen\Analyzer\AnalyzeState;
use ApiGen\Analyzer\AnalyzeTask;
use ApiGen\Analyzer\AnalyzeTaskHandlerFactory;
use ApiGen\Info\ClassLikeInfo;
use ApiGen\Info\ClassLikeReferenceInfo;
use ApiGen\Info\ErrorInfo;
use ApiGen\Info\ErrorKind;
use ApiGen\Info\FunctionInfo;
use ApiGen\Info\MissingInfo;
use ApiGen\Info\NameInfo;
use ApiGen\Scheduler\SchedulerFactory;
use Symfony\Component\Console\Helper\ProgressBar;

use function count;
use function implode;


class Analyzer
{
	public function __construct(
		protected SchedulerFactory $schedulerFactory,
		protected Locator $locator,
	) {
	}


	/**
	 * Analyzes the given source files and their discovered dependencies.
	 *
	 * @param string[] $files indexed by []
	 */
	public function analyze(
		ProgressBar $progressBar,
		array $files,
	): AnalyzeResult
	{
		$scheduler = $this->schedulerFactory->create(
			AnalyzeTaskHandlerFactory::class,
			context: null,
		);

		$state = new AnalyzeState(
			$progressBar,
			$scheduler,
		);

		$this->schedulePrimaryFiles(
			$state,
			$files,
		);

		$this->processScheduledTasks(
			$state,
		);

		$this->processMissingSymbols(
			$state,
		);

		return $this->createAnalyzeResult(
			$state,
		);
	}


	/**
	 * Schedules all explicitly provided source files.
	 *
	 * @param string[] $files indexed by []
	 */
	protected function schedulePrimaryFiles(
		AnalyzeState $state,
		array $files,
	): void
	{
		foreach ($files as $file) {
			$this->scheduleFile(
				$state,
				$file,
				primary: true,
			);
		}
	}


	/**
	 * Processes all scheduled analyzer tasks.
	 */
	protected function processScheduledTasks(
		AnalyzeState $state,
	): void
	{
		/** @var AnalyzeTask $task */
		foreach (
			$state->scheduler->process()
			as $task => $result
		) {
			$this->processTaskResult(
				$result,
				$state,
			);

			$this->updateProgress(
				$state,
				$task,
			);
		}
	}


	/**
	 * Updates the progress bar after a task has been processed.
	 */
	protected function updateProgress(
		AnalyzeState $state,
		AnalyzeTask $task,
	): void
	{
		$state->progressBar->setMessage(
			$task->sourceFile,
		);

		$state->progressBar->advance();
	}


	/**
	 * Processes unresolved symbols after all scheduled tasks finish.
	 */
	protected function processMissingSymbols(
		AnalyzeState $state,
	): void
	{
		foreach ($state->missing as $missing) {
			$referencedBy = $this->findReferencedBy(
				$state,
				$missing,
			);

			if (
				$referencedBy === null
				|| !$referencedBy->primary
			) {
				continue;
			}

			$state->errors[
				ErrorKind::MissingSymbol->name
			][] = $this->createMissingSymbolError(
				$missing,
				$referencedBy,
			);
		}
	}


	/**
	 * Locates the symbol that references a missing dependency.
	 */
	protected function findReferencedBy(
		AnalyzeState $state,
		MissingInfo $missing,
	): ClassLikeInfo|FunctionInfo|null
	{
		$name = $missing->referencedBy->fullLower;

		return $state->classLikes[$name]
			?? $state->functions[$name]
			?? null;
	}


	/**
	 * Builds the final analysis result.
	 */
	protected function createAnalyzeResult(
		AnalyzeState $state,
	): AnalyzeResult
	{
		return new AnalyzeResult(
			$state->classLikes + $state->missing,
			$state->functions,
			$state->errors,
		);
	}


	protected function scheduleFile(
		AnalyzeState $state,
		string $file,
		bool $primary,
	): void
	{
		$file = Helpers::realPath($file);

		if (isset($state->files[$file])) {
			return;
		}

		$state->files[$file] = true;

		$state->progressBar->setMaxSteps(
			count($state->files),
		);

		$state->scheduler->schedule(
			new AnalyzeTask(
				$file,
				$primary,
			),
		);
	}


	/**
	 * @param array<ClassLikeInfo | FunctionInfo | ClassLikeReferenceInfo | ErrorInfo> $result
	 */
	protected function processTaskResult(
		array $result,
		AnalyzeState $state,
	): void
	{
		foreach ($result as $info) {
			$this->processInfo(
				$state,
				$info,
			);
		}
	}


	/**
	 * Dispatches a processed information object to the correct handler.
	 */
	protected function processInfo(
		AnalyzeState $state,
		ClassLikeInfo|FunctionInfo|ClassLikeReferenceInfo|ErrorInfo $info,
	): void
	{
		match (true) {
			$info instanceof ClassLikeReferenceInfo =>
				$this->processClassLikeReference(
					$state,
					$info,
				),

			$info instanceof ClassLikeInfo =>
				$this->processClassLike(
					$state,
					$info,
				),

			$info instanceof FunctionInfo =>
				$this->processFunction(
					$state,
					$info,
				),

			$info instanceof ErrorInfo =>
				$this->processError(
					$state,
					$info,
				),
		};
	}


	protected function processClassLikeReference(
		AnalyzeState $state,
		ClassLikeReferenceInfo $info,
	): void
	{
		if ($state->prevName === null) {
			return;
		}

		if (
			isset($state->classLikes[$info->fullLower])
			|| isset($state->missing[$info->fullLower])
		) {
			return;
		}

		$name = new NameInfo(
			$info->full,
			$info->fullLower,
		);

		$state->missing[$info->fullLower] =
			new MissingInfo(
				$name,
				$state->prevName,
			);

		$file = $this->locator->locate($info);

		if ($file === null) {
			return;
		}

		$this->scheduleFile(
			$state,
			$file,
			primary: false,
		);
	}


	protected function processClassLike(
		AnalyzeState $state,
		ClassLikeInfo $info,
	): void
	{
		$key = $info->name->fullLower;

		$existing =
			$state->classLikes[$key]
			?? null;

		if (
			$existing === null
			|| (
				$info->primary
				&& !$existing->primary
			)
		) {
			unset($state->missing[$key]);

			$state->classLikes[$key] = $info;

			$state->prevName =
				$info->name;

			return;
		}

		if ($info->primary) {
			$this->registerDuplicateSymbol(
				$state,
				$info,
				$existing,
			);

			return;
		}

		$state->prevName = null;
	}


	protected function processFunction(
		AnalyzeState $state,
		FunctionInfo $info,
	): void
	{
		$key = $info->name->fullLower;

		$existing =
			$state->functions[$key]
			?? null;

		if (
			$existing === null
			|| (
				$info->primary
				&& !$existing->primary
			)
		) {
			$state->functions[$key] = $info;

			$state->prevName =
				$info->name;

			return;
		}

		if ($info->primary) {
			$this->registerDuplicateSymbol(
				$state,
				$info,
				$existing,
			);

			return;
		}

		$state->prevName = null;
	}


	protected function registerDuplicateSymbol(
		AnalyzeState $state,
		ClassLikeInfo|FunctionInfo $info,
		ClassLikeInfo|FunctionInfo $existing,
	): void
	{
		$state->errors[
			ErrorKind::DuplicateSymbol->name
		][] = $this->createDuplicateSymbolError(
			$info,
			$existing,
		);

		$state->prevName = null;
	}


	protected function processError(
		AnalyzeState $state,
		ErrorInfo $info,
	): void
	{
		$state->errors[
			$info->kind->name
		][] = $info;

		$state->prevName = null;
	}


	protected function createMissingSymbolError(
		MissingInfo $dependency,
		ClassLikeInfo|FunctionInfo $referencedBy,
	): ErrorInfo
	{
		$message = implode(
			"\n",
			[
				"Missing {$dependency->name->full}",
				"referenced by {$referencedBy->name->full}",
			],
		);

		return new ErrorInfo(
			ErrorKind::MissingSymbol,
			$message,
		);
	}


	protected function createDuplicateSymbolError(
		ClassLikeInfo|FunctionInfo $info,
		ClassLikeInfo|FunctionInfo $first,
	): ErrorInfo
	{
		$message = implode(
			"\n",
			[
				"Multiple definitions of {$info->name->full}.",
				"The first definition was found in {$first->file} on line {$first->startLine}",
				"and then another one was found in {$info->file} on line {$info->startLine}",
			],
		);

		return new ErrorInfo(
			ErrorKind::DuplicateSymbol,
			$message,
		);
	}
}
